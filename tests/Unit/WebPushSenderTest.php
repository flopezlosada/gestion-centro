<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use App\Service\PushDeliveryReport;
use App\Service\PushTransport;
use App\Service\WebPushSender;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The policy around Web Push, which until now had no test at all: which devices get pruned, which
 * failures are merely logged, and that nothing ever escapes to break the operation that triggered the
 * notice.
 *
 * The distinction that matters and is easy to get wrong: a subscription reported as GONE (the push
 * service answered 404/410 because the browser threw it away) must be DELETED, while any other failure
 * — timeout, 5xx, encryption error — must NOT cost the user their device. Getting that backwards
 * silently unsubscribes the whole staff the first time the push service has a bad afternoon.
 *
 * The wire is stubbed through {@see PushTransport}; the 404/410 reading itself is pinned separately in
 * {@see WebPushTransportTest} against the library's own reports.
 */
final class WebPushSenderTest extends TestCase
{
    private const string ENDPOINT_A = 'https://fcm.googleapis.com/fcm/send/aaa';
    private const string ENDPOINT_B = 'https://updates.push.services.mozilla.com/wpush/v2/bbb';

    public function testDoesNothingWhenPushIsNotConfigured(): void
    {
        // Local/dev without VAPID keys: not an error, a silent no-op. It must not even look up the
        // user's devices, which is the cheap proof that no push was attempted.
        $subscriptions = $this->createMock(PushSubscriptionRepository::class);
        $subscriptions->expects(self::never())->method('findByUser');

        $sender = new WebPushSender(
            $subscriptions,
            $this->entityManagerExpectingNoWrites(),
            $this->createMock(LoggerInterface::class),
            $this->transport($this->sent(), configured: false),
        );

        $sender->sendToUser($this->user(), 'Hola', null, '/avisos');
    }

    public function testDoesNotSendWhenTheUserHasNoDevices(): void
    {
        $sent = $this->sent();

        $sender = new WebPushSender(
            $this->repositoryReturning([]),
            $this->entityManagerExpectingNoWrites(),
            $this->createMock(LoggerInterface::class),
            $this->transport($sent, [PushDeliveryReport::delivered(self::ENDPOINT_A)]),
        );

        $sender->sendToUser($this->user(), 'Hola', null, '/avisos');

        self::assertCount(0, $sent, 'Sin dispositivos no hay nada que enviar.');
    }

    public function testPrunesOnlyTheSubscriptionReportedAsGone(): void
    {
        $user = $this->user();
        $dead = new PushSubscription($user, self::ENDPOINT_A, 'p256dh-a', 'auth-a');
        $alive = new PushSubscription($user, self::ENDPOINT_B, 'p256dh-b', 'auth-b');

        $removed = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(
            static function (object $entity) use (&$removed): void {
                $removed[] = $entity;
            },
        );
        // One flush for the whole batch, not one per pruned device.
        $entityManager->expects(self::once())->method('flush');

        $sender = new WebPushSender(
            $this->repositoryReturning([$dead, $alive]),
            $entityManager,
            $this->createMock(LoggerInterface::class),
            $this->transport($this->sent(), [
                PushDeliveryReport::gone(self::ENDPOINT_A, 'Unsubscribed or expired'),
                PushDeliveryReport::delivered(self::ENDPOINT_B),
            ]),
        );

        $sender->sendToUser($user, 'Hola', 'cuerpo', '/avisos');

        self::assertSame([$dead], $removed, 'Solo se borra el dispositivo que el servicio da por muerto.');
    }

    public function testAFailureThatIsNotGoneIsLoggedButKeepsTheDevice(): void
    {
        $user = $this->user();
        $subscription = new PushSubscription($user, self::ENDPOINT_A, 'p256dh-a', 'auth-a');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::stringContains('push'),
            self::callback(static fn (array $context): bool => self::ENDPOINT_A === $context['endpoint']
                && 'Gateway timeout' === $context['reason']),
        );

        $sender = new WebPushSender(
            $this->repositoryReturning([$subscription]),
            $this->entityManagerExpectingNoWrites(),
            $logger,
            $this->transport($this->sent(), [PushDeliveryReport::failed(self::ENDPOINT_A, 'Gateway timeout')]),
        );

        $sender->sendToUser($user, 'Hola', null, '/avisos');
    }

    public function testAGoneReportForAnUnknownEndpointPrunesNothing(): void
    {
        // Defensive: the push service echoing back an endpoint we did not queue must not turn into a
        // stray delete, and must not trigger a flush either.
        $user = $this->user();

        $sender = new WebPushSender(
            $this->repositoryReturning([new PushSubscription($user, self::ENDPOINT_A, 'p', 'a')]),
            $this->entityManagerExpectingNoWrites(),
            $this->createMock(LoggerInterface::class),
            $this->transport($this->sent(), [PushDeliveryReport::gone('https://example.test/otro')]),
        );

        $sender->sendToUser($user, 'Hola', null, '/avisos');
    }

    public function testATransportFailureIsSwallowedAndLogged(): void
    {
        // The in-app notice is already saved by the time we get here, so a broken push must never
        // bubble up and roll back the operation that triggered it.
        $user = $this->user();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $transport = new class implements PushTransport {
            public function isConfigured(): bool
            {
                return true;
            }

            public function send(array $subscriptions, string $payload): iterable
            {
                throw new \RuntimeException('Unable to create the key');
            }
        };

        $sender = new WebPushSender(
            $this->repositoryReturning([new PushSubscription($user, self::ENDPOINT_A, 'p', 'a')]),
            $this->entityManagerExpectingNoWrites(),
            $logger,
            $transport,
        );

        $sender->sendToUser($user, 'Hola', null, '/avisos');
    }

    public function testThePayloadCarriesTitleBodyAndPathUnescaped(): void
    {
        // The service worker reads exactly these three keys, and accented Spanish must travel readable
        // rather than as \uXXXX escapes (the notice is shown as-is on the phone).
        $user = $this->user();
        $sent = $this->sent();

        $sender = new WebPushSender(
            $this->repositoryReturning([new PushSubscription($user, self::ENDPOINT_A, 'p', 'a')]),
            $this->entityManagerExpectingNoWrites(),
            $this->createMock(LoggerInterface::class),
            $this->transport($sent, [PushDeliveryReport::delivered(self::ENDPOINT_A)]),
        );

        $sender->sendToUser($user, 'Guardia reasignada', 'Mañana a 3.ª hora', '/guardias/mias');

        self::assertSame(
            '{"title":"Guardia reasignada","body":"Mañana a 3.ª hora","url":"/guardias/mias"}',
            $sent[0] ?? null,
        );
    }

    public function testAMissingBodyTravelsAsAnEmptyString(): void
    {
        // The service worker does `body: data.body`, so the key has to be there even when there is
        // nothing to say — null would render "null" or blow up depending on the browser.
        $user = $this->user();
        $sent = $this->sent();

        $sender = new WebPushSender(
            $this->repositoryReturning([new PushSubscription($user, self::ENDPOINT_A, 'p', 'a')]),
            $this->entityManagerExpectingNoWrites(),
            $this->createMock(LoggerInterface::class),
            $this->transport($sent, [PushDeliveryReport::delivered(self::ENDPOINT_A)]),
        );

        $sender->sendToUser($user, 'Aviso', null, '/avisos');

        self::assertStringContainsString('"body":""', $sent[0] ?? '');
    }

    /**
     * A fresh recorder for the payloads handed to the transport.
     *
     * @return \ArrayObject<int, string> the empty recorder
     */
    private function sent(): \ArrayObject
    {
        /** @var \ArrayObject<int, string> $sent */
        $sent = new \ArrayObject();

        return $sent;
    }

    /**
     * A stub transport that records every payload it was asked to send and replays canned reports.
     * Follows the recording-collaborator shape already used by {@see CopyShopMailerTest}: the state
     * lives outside the anonymous class so the test can read it without naming that class.
     *
     * @param \ArrayObject<int, string> $sent       collects the payloads handed to the transport
     * @param list<PushDeliveryReport>  $reports    the outcomes to report back
     * @param bool                      $configured whether push is configured
     *
     * @return PushTransport the recording stub
     */
    private function transport(\ArrayObject $sent, array $reports = [], bool $configured = true): PushTransport
    {
        return new class($sent, $reports, $configured) implements PushTransport {
            /**
             * @param \ArrayObject<int, string> $sent    where to record the payloads
             * @param list<PushDeliveryReport>  $reports the outcomes to report back
             */
            public function __construct(
                private readonly \ArrayObject $sent,
                private readonly array $reports,
                private readonly bool $configured,
            ) {
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function send(array $subscriptions, string $payload): iterable
            {
                $this->sent[] = $payload;

                return $this->reports;
            }
        };
    }

    /**
     * A subscription repository that returns a fixed device list for any user.
     *
     * @param list<PushSubscription> $subscriptions the devices to return
     *
     * @return PushSubscriptionRepository the stub
     */
    private function repositoryReturning(array $subscriptions): PushSubscriptionRepository
    {
        $repository = $this->createMock(PushSubscriptionRepository::class);
        $repository->method('findByUser')->willReturn($subscriptions);

        return $repository;
    }

    /**
     * An entity manager that fails the test if anything is removed or flushed.
     *
     * @return EntityManagerInterface the strict stub
     */
    private function entityManagerExpectingNoWrites(): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('remove');
        $entityManager->expects(self::never())->method('flush');

        return $entityManager;
    }

    /**
     * A recipient with just enough state for the sender (its e-mail is used in the error log).
     *
     * @return User the recipient
     */
    private function user(): User
    {
        return (new User())->setEmail('docente@ieslacabrera.test');
    }
}
