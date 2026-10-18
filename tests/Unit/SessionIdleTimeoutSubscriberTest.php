<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventSubscriber\SessionIdleTimeoutSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
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
 * has not signed in yet is never expired: it would bounce the login form itself. The families' area
 * expires the same, to its own login and with its own cookie.
 */
final class SessionIdleTimeoutSubscriberTest extends TestCase
{
    private const TIMEOUT = 1800;
    private const COOKIE = 'REMEMBERME';
    private const FAMILY_COOKIE = 'REMEMBERME_FAMILY';

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
        $event = $this->requestAfterIdling(self::TIMEOUT + 60, [], signedInTo: null);

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

    public function testAStaleFamilySessionIsClosedAndSentToTheFamiliesLogin(): void
    {
        $event = $this->requestAfterIdling(self::TIMEOUT + 60, [], signedInTo: 'families');

        $this->subscriber('families')->onKernelRequest($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
        self::assertSame('/familias/entrar', $event->getResponse()->getTargetUrl());
    }

    public function testAStaleFamilySessionOfARememberedFamilyIsClosedButLetThrough(): void
    {
        $event = $this->requestAfterIdling(self::TIMEOUT + 60, [self::FAMILY_COOKIE => 'firmada'], signedInTo: 'families');

        $this->subscriber('families')->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertFalse($event->getRequest()->getSession()->has('_security_families'));
    }

    public function testTheStaffCookieDoesNotLetAFamilyThrough(): void
    {
        // The staff cookie goes with every path, /familias included: a teacher remembered on this
        // device says nothing about the family that signed in here.
        $event = $this->requestAfterIdling(self::TIMEOUT + 60, [self::COOKIE => 'firmada'], signedInTo: 'families');

        $this->subscriber('families')->onKernelRequest($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
        self::assertSame('/familias/entrar', $event->getResponse()->getTargetUrl());
    }

    public function testAStaleStaffSignInIsDroppedFromTheFamiliesAreaWithoutStoppingTheRequest(): void
    {
        // Otherwise browsing /familias would keep the staff sign-in in the same session alive. But the
        // family here is not signed in to it: their request (asking for their link) must go on, with
        // the rest of the session — the form's CSRF token — intact.
        $event = $this->requestAfterIdling(self::TIMEOUT + 60, [], signedInTo: 'main');
        $session = $event->getRequest()->getSession();
        $session->set('_csrf/family_login', 'token del formulario');

        $this->subscriber('families')->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertFalse($session->has('_security_main'));
        self::assertSame('token del formulario', $session->get('_csrf/family_login'));
    }

    public function testWithBothSignedInARememberedFamilyIsLetThroughByItsOwnCookie(): void
    {
        $event = $this->requestAfterIdling(self::TIMEOUT + 60, [self::FAMILY_COOKIE => 'firmada'], signedInTo: 'main');
        $event->getRequest()->getSession()->set('_security_families', 'token serializado');

        $this->subscriber('families')->onKernelRequest($event);

        self::assertNull($event->getResponse());
        self::assertFalse($event->getRequest()->getSession()->has('_security_main'), 'la sesión entera se invalida');
    }

    public function testARequestOutsideBothFirewallsIsTreatedAsTheStaffs(): void
    {
        $event = $this->requestAfterIdling(self::TIMEOUT + 60, []);

        $this->subscriber(null)->onKernelRequest($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
        self::assertSame('/login', $event->getResponse()->getTargetUrl());
    }

    /**
     * The subscriber as the given firewall would see the request.
     *
     * @param string|null $firewall the firewall serving the request; null for none
     */
    private function subscriber(?string $firewall = 'main'): SessionIdleTimeoutSubscriber
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route): string => ['login' => '/login', 'family_login' => '/familias/entrar'][$route]);
        $security = $this->createStub(Security::class);
        $security->method('getFirewallConfig')->willReturn(null === $firewall ? null : new FirewallConfig($firewall, 'security.user_checker', context: $firewall));

        return new SessionIdleTimeoutSubscriber($urls, $security, self::TIMEOUT, self::COOKIE, self::FAMILY_COOKIE);
    }

    /**
     * A main request carrying an existing session last used the given seconds ago, signed in to the
     * staff firewall unless told otherwise.
     *
     * @param array<string, string> $cookies    extra cookies besides the session one
     * @param string|null           $signedInTo the firewall holding a sign-in in the session; null for none
     */
    private function requestAfterIdling(int $idleSeconds, array $cookies, ?string $signedInTo = 'main'): RequestEvent
    {
        $lastUsed = time() - $idleSeconds;
        $storage = new MockArraySessionStorage();
        $storage->setSessionData(['_sf2_meta' => [
            MetadataBag::CREATED => $lastUsed,
            MetadataBag::UPDATED => $lastUsed,
            MetadataBag::LIFETIME => 0,
        ], '_sf2_attributes' => null !== $signedInTo ? ['_security_'.$signedInTo => 'token serializado'] : []]);
        $session = new Session($storage);

        $request = new Request(cookies: [$session->getName() => 'id-de-sesion'] + $cookies);
        $request->setSession($session);

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
