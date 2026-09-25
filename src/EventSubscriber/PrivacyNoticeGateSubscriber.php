<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\PrivacyNoticeAckRepository;
use App\Repository\PrivacyNoticeRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Keeps a signed-in person on the data-protection reading screen until they have read the version in
 * force. Checked on every request, not only at sign-in: a new version published while someone is
 * already working has to reach them too, and a session can last all day.
 *
 * Nothing is blocked while no version has been published. Runs after the firewall (priority 4 < 8), so
 * the signed-in user is already known.
 */
final class PrivacyNoticeGateSubscriber implements EventSubscriberInterface
{
    /** Session key holding the id of the version this session already acknowledged, to skip the query. */
    public const SESSION_KEY = 'privacy_notice_ack';

    /** Routes reachable without having read it: the reading screen itself, its public copy, and leaving. */
    private const OPEN_ROUTES = ['privacy_notice_read', 'privacy_notice_ack', 'privacy_notice_public', 'app_logout'];

    public function __construct(
        private readonly Security $security,
        private readonly PrivacyNoticeRepository $notices,
        private readonly PrivacyNoticeAckRepository $acks,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 4],
        ];
    }

    /**
     * Redirects to the reading screen when the signed-in person has not read the current version,
     * carrying where they were going so they land there afterwards.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');
        // `_wdt`/`_profiler`: the dev toolbar, loaded by every page, must not bounce.
        if (\in_array($route, self::OPEN_ROUTES, true) || str_starts_with($route, '_')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $current = $this->notices->findCurrent();
        if (null === $current) {
            return;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if (null !== $session && $session->get(self::SESSION_KEY) === $current->getId()) {
            return;
        }
        if ($this->acks->hasAcknowledged($user, $current)) {
            $session?->set(self::SESSION_KEY, $current->getId());

            return;
        }

        // A script's request (fetch) cannot show a screen: redirecting it would hand it the reading page as
        // a 200 and it would carry on as if its call had worked (a push subscription "saved", a panel
        // filled with the wrong HTML). It gets a plain refusal instead, and the next page load shows the
        // screen. Sec-Fetch-Mode is "navigate" only for real page loads; without the header, redirect.
        $mode = $request->headers->get('Sec-Fetch-Mode');
        if (null !== $mode && 'navigate' !== $mode) {
            $event->setResponse(new Response('Lee la información de protección de datos para continuar.', Response::HTTP_FORBIDDEN));

            return;
        }

        // Only a page the person was looking at is worth coming back to; after a POST, the form is gone.
        $back = $request->isMethod('GET') ? $request->getRequestUri() : null;
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('privacy_notice_read', null !== $back ? ['volver' => $back] : [])));
    }
}
