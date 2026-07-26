<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Enum\AccessDenial;
use App\Security\AccessGate;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Drops the session of someone whose access was revoked while they were already inside.
 *
 * Symfony only consults the user checker when a session is created, never when an existing one is
 * reused (UserCheckerListener is subscribed to CheckPassportEvent alone, and
 * ContextListener::refreshUser() calls no checker), so without this a deactivated person keeps
 * browsing until their session expires.
 *
 * Revocation is deliberately not the same as closing sign-in for everyone:
 *
 * - A deactivated account is thrown out on its next request, always. It is a decision about one
 *   person and it must take effect now.
 * - Closing sign-in globally only stops NEW sign-ins. Whoever is already working carries on until
 *   they leave, so flipping the switch does not yank the application from under the whole staff.
 * - Withdrawing one person's early access while sign-in is closed does throw them out, and that is
 *   what {@see User::getAccessChangedAt()} distinguishes: their access changed after their session
 *   started, so the session no longer reflects what they are allowed to do.
 *
 * Runs at priority 7, just after the firewall (8), so the user is already resolved.
 */
class AccessRevocationSubscriber implements EventSubscriberInterface
{
    /**
     * Routes that must stay reachable even to a revoked user: the sign-in pages (redirecting to
     * them from themselves would loop) and logout (which is exactly what we want them to do).
     */
    private const PUBLIC_ROUTES = ['login', 'login_check', 'app_logout', 'connect_google', 'connect_google_check'];

    public function __construct(
        private readonly Security $security,
        private readonly AccessGate $gate,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 7],
        ];
    }

    /**
     * Invalidates the session and sends the user back to the login page when their access has been
     * revoked since they signed in.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (\in_array($request->attributes->get('_route'), self::PUBLIC_ROUTES, true)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $denial = $this->gate->denialFor($user);
        if (null === $denial || !$this->shouldExpel($user, $denial, $session)) {
            return;
        }

        $session->invalidate();
        // Written to the standard authentication-error store, not to a flash: the login page reads
        // that store (AuthenticationUtils::getLastAuthenticationError) and renders it in the card,
        // while it renders no flashes at all. Same channel GoogleAuthenticator uses on failure.
        $session->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, new CustomUserMessageAuthenticationException($denial->message()));
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('login')));
    }

    /**
     * Whether this denial justifies ending an already-open session, rather than only barring new
     * sign-ins.
     *
     * @param User             $user    the signed-in user
     * @param AccessDenial     $denial  why the gate turns them away
     * @param SessionInterface $session their current session, dated by its metadata
     *
     * @return bool true when the session must be dropped now
     */
    private function shouldExpel(User $user, AccessDenial $denial, SessionInterface $session): bool
    {
        if (AccessDenial::ACCOUNT_DISABLED === $denial) {
            return true;
        }

        $changedAt = $user->getAccessChangedAt();
        if (null === $changedAt) {
            // Sign-in was closed around a user nobody has touched: leave them working.
            return false;
        }

        // getCreated() is the moment the session was (re)issued: authenticating migrates the
        // session id, and SessionAuthenticationStrategy::MIGRATE (Symfony's default) destroys the
        // old one, which re-stamps the metadata. So this really compares "revoked" against
        // "signed in", not against some older visit.
        //
        // Both are whole seconds, hence >= and not >: a revocation landing in the same second as
        // the sign-in must still count. It cannot backfire, because we only get here when the gate
        // is already turning the user away — a change that GRANTS access never reaches this method.
        return $changedAt->getTimestamp() >= $session->getMetadataBag()->getCreated();
    }
}
