<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Web Push subscription endpoints: a logged-in user can register and remove the current browser's
 * subscription; the calls are CSRF-guarded and a re-subscribe upserts instead of duplicating.
 */
final class PushSubscriptionControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email = 'profe@centro.test'): User
    {
        $user = (new User())->setFullName('Profe Test')->setEmail($email);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Reads the push CSRF token the app renders in a meta tag, so a follow-up POST is valid in the same session. */
    private function pushToken(): string
    {
        $crawler = $this->client->request('GET', '/avisos');
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('meta[name="push-csrf"]')->attr('content');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $path, array $payload, string $token): void
    {
        $this->client->request('POST', $path, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], (string) json_encode($payload));
    }

    private function repository(): PushSubscriptionRepository
    {
        return self::getContainer()->get(PushSubscriptionRepository::class);
    }

    private function samplePayload(string $endpoint = 'https://push.example/endpoint/abc'): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'BPublicKeyBase64Url', 'auth' => 'AuthSecretBase64Url'],
        ];
    }

    public function testSubscribeStoresTheBrowserSubscription(): void
    {
        $user = $this->user();
        $this->client->loginUser($user);

        $this->postJson('/push/subscribe', $this->samplePayload(), $this->pushToken());

        self::assertResponseStatusCodeSame(201);
        $this->em->clear();
        $stored = $this->repository()->findOneByEndpoint('https://push.example/endpoint/abc');
        self::assertNotNull($stored);
        self::assertSame('BPublicKeyBase64Url', $stored->getP256dh());
        self::assertSame('AuthSecretBase64Url', $stored->getAuth());
        self::assertSame($user->getId(), $stored->getUser()->getId());
    }

    public function testSubscribeIsRejectedWithAnInvalidCsrfToken(): void
    {
        $this->client->loginUser($this->user());

        $this->postJson('/push/subscribe', $this->samplePayload(), 'wrong-token');

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->repository()->findAll());
    }

    public function testSubscribeRejectsAnIncompletePayload(): void
    {
        $this->client->loginUser($this->user());

        $this->postJson('/push/subscribe', ['endpoint' => 'https://push.example/x'], $this->pushToken());

        self::assertResponseStatusCodeSame(400);
        self::assertCount(0, $this->repository()->findAll());
    }

    public function testResubscribingTheSameEndpointDoesNotDuplicate(): void
    {
        $this->client->loginUser($this->user());
        $token = $this->pushToken();

        $this->postJson('/push/subscribe', $this->samplePayload(), $token);
        $this->postJson('/push/subscribe', $this->samplePayload(), $token);

        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->repository()->findAll());
    }

    public function testUnsubscribeRemovesTheSubscription(): void
    {
        $user = $this->user();
        $subscription = new PushSubscription($user, 'https://push.example/endpoint/xyz', 'pk', 'auth');
        $this->em->persist($subscription);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->postJson('/push/unsubscribe', ['endpoint' => 'https://push.example/endpoint/xyz'], $this->pushToken());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->repository()->findAll());
    }

    public function testUnsubscribeDoesNotTouchAnotherUsersSubscription(): void
    {
        $owner = $this->user('owner@centro.test');
        $subscription = new PushSubscription($owner, 'https://push.example/endpoint/owned', 'pk', 'auth');
        $this->em->persist($subscription);
        $this->em->flush();

        // A different logged-in user must not be able to remove someone else's subscription.
        $this->client->loginUser($this->user('intruder@centro.test'));
        $this->postJson('/push/unsubscribe', ['endpoint' => 'https://push.example/endpoint/owned'], $this->pushToken());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->repository()->findAll());
    }
}
