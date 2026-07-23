<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Closes a session after a period of inactivity (sliding idle timeout).
 *
 * Symfony has no built-in idle timeout: {@see \Symfony\Component\HttpFoundation\Session\Storage\MetadataBag}
 * only tracks when the session was last used, and PHP's gc_maxlifetime relies on probabilistic
 * garbage collection, so it never expires a session at a predictable moment. This subscriber does
 * the deterministic check on every request instead.
 *
 * Disabled when the timeout is 0 (dev/test); production sets it via app.session_idle_timeout.
 * Runs before the firewall (priority 9 > firewall's 8): when the session is stale it is invalidated
 * and a redirect is returned, which stops propagation, so the firewall never authenticates a
 * doomed session.
 */
class SessionIdleTimeoutSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly int $sessionIdleTimeout,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 9],
        ];
    }

    /**
     * Invalidates the session and redirects to the login page when the time since its last use
     * exceeds the configured idle timeout. No-op when the timeout is disabled, on sub-requests, or
     * when the request carries no pre-existing session cookie (nothing to expire).
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (0 === $this->sessionIdleTimeout || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession() || !$request->hasPreviousSession()) {
            return;
        }

        $session = $request->getSession();
        if (!$session->isStarted()) {
            // Starting an already-existing session (the cookie is present) just reloads its data;
            // it is also needed a moment later for the authenticated request anyway.
            $session->start();
        }

        // getLastUsed() returns the previous request's timestamp: MetadataBag::initialize() captures
        // it on start() before refreshing it to "now", so it is a true measure of idle time.
        $lastUsed = $session->getMetadataBag()->getLastUsed();
        if ($lastUsed <= 0 || (time() - $lastUsed) <= $this->sessionIdleTimeout) {
            return;
        }

        $session->invalidate();
        // getSession() is typed as SessionInterface, which has no flash bag; the concrete session
        // (FlashBagAwareSessionInterface) does. Guard the cast so the warning is best-effort.
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('warning', 'Tu sesión se ha cerrado por inactividad.');
        }
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('login')));
    }
}
