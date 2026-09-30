<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventSubscriber\SessionIdleTimeoutSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A stale signed-in session is closed and sent to the login, except for whoever ticked "Recordarme":
 * their request goes on so the firewall signs them back in from the cookie. A session of someone who
 * has not signed in yet is never expired: it would bounce the login form itself.
 */
final class SessionIdleTimeoutSubscriberTest extends TestCase
{
    private const TIMEOUT = 1800;
    private const COOKIE = 'REMEMBERME';

    public function testAStaleSessionIsClosedAndSentToTheLogin(): void
    {
        $event = $this->requestAfterIdling(self::TIMEOUT + 60, []);

        $this->subscriber()->onKernelRequest($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
        self::assertSame('/login', $event->getResponse()->getTargetUrl());
    }

    public function testAStaleSessionOfARememberedVisitorIsClosedButLetThrough(): void
    {
        $event = $this->requestAfterIdling(self::TIMEOUT + 60, [self::COOKIE => 'firmada']);
        $session = $event->getRequest()->getSession();
        $session->set('marca', 'de la sesión vieja');

        $this->subscriber()->onKernelRequest($event);

        self::assertNull($event->getResponse(), 'sin redirección: el firewall lo vuelve a meter con la cookie');
        self::assertFalse($session->has('marca'), 'la sesión caducada se invalida igual');
    }

    public function testAStaleAnonymousSessionIsLeftAlone(): void
    {
        // The login page left open over the timeout, then the form sent: it must reach the
        // controller and send the link, not come back to the login with nothing said.
        $event = $this->requestAfterIdling(self::TIMEOUT + 60, [], signedIn: false);

        $this->subscriber()->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testAFreshSessionIsLeftAloneWithOrWithoutTheCookie(): void
    {
        foreach ([[], [self::COOKIE => 'firmada']] as $cookies) {
            $event = $this->requestAfterIdling(60, $cookies);

            $this->subscriber()->onKernelRequest($event);

            self::assertNull($event->getResponse());
        }
    }

    private function subscriber(): SessionIdleTimeoutSubscriber
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/login');

        return new SessionIdleTimeoutSubscriber($urls, self::TIMEOUT, self::COOKIE);
    }

    /**
     * A main request carrying an existing session last used the given seconds ago, signed in
     * unless told otherwise.
     *
     * @param array<string, string> $cookies extra cookies besides the session one
     */
    private function requestAfterIdling(int $idleSeconds, array $cookies, bool $signedIn = true): RequestEvent
    {
        $lastUsed = time() - $idleSeconds;
        $storage = new MockArraySessionStorage();
        $storage->setSessionData(['_sf2_meta' => [
            MetadataBag::CREATED => $lastUsed,
            MetadataBag::UPDATED => $lastUsed,
            MetadataBag::LIFETIME => 0,
        ], '_sf2_attributes' => $signedIn ? ['_security_main' => 'token serializado'] : []]);
        $session = new Session($storage);

        $request = new Request(cookies: [$session->getName() => 'id-de-sesion'] + $cookies);
        $request->setSession($session);

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
