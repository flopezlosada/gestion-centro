<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\FamilyVolunteer;
use App\Repository\FamilyConsentRepository;
use App\Repository\PrivacyNoticeRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Keeps a signed-in family on the consent screen until it has accepted the families' version in force.
 * The staff's counterpart is {@see PrivacyNoticeGateSubscriber}, with one difference on purpose: it is
 * fail-closed. A family's data is processed on its consent, so while no text has been published there is
 * nothing to consent to, and the area stays shut instead of opening without it.
 *
 * Checked on every request, so a new version reaches a family already signed in. Runs after the firewall
 * (priority 4 < 8), so the signed-in account is already known.
 */
final class FamilyConsentGateSubscriber implements EventSubscriberInterface
{
    /** Routes reachable without having accepted: the consent screen, its public copy, leaving, and signing out. */
    private const OPEN_ROUTES = ['family_consent', 'family_consent_give', 'family_privacy_public', 'family_leave', 'family_logout'];

    public function __construct(
        private readonly Security $security,
        private readonly PrivacyNoticeRepository $notices,
        private readonly FamilyConsentRepository $consents,
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
     * Sends a family that has not accepted the current version (or finds none to accept) to the consent
     * screen.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');
        if (\in_array($route, self::OPEN_ROUTES, true) || str_starts_with($route, '_')) {
            return;
        }

        $family = $this->security->getUser();
        if (!$family instanceof FamilyVolunteer) {
            return;
        }

        // Asked of the database on every request, with no shortcut kept in the session: the session outlives
        // a family's sign-out (it is shared with the staff firewall and not invalidated), so a mark left there
        // by one family would let the next one on the same browser through without consenting. Two indexed
        // lookups per request, in an area of a few dozen accounts.
        $current = $this->notices->findCurrentForFamilies();
        if (null !== $current && $this->consents->hasConsented($family, $current)) {
            return;
        }

        // A script's request cannot show a screen: it gets a plain refusal, as in the staff's gate.
        $mode = $request->headers->get('Sec-Fetch-Mode');
        if (null !== $mode && 'navigate' !== $mode) {
            $event->setResponse(new Response('Acepta la información de protección de datos para continuar.', Response::HTTP_FORBIDDEN));

            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('family_consent')));
    }
}
