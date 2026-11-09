<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FamilyConsent;
use App\Entity\FamilyPrivacyNotice;
use App\Entity\FamilyVolunteer;
use App\EventSubscriber\FamilyConsentGateSubscriber;
use App\Repository\FamilyConsentRepository;
use App\Repository\PrivacyNoticeRepository;
use App\Security\Voter\ModuleVoter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A family's data protection: the public copy of its information, the consent screen the family must get
 * through once per version ({@see FamilyConsentGateSubscriber}), and leaving — which deletes the account
 * and everything hanging from it. Withdrawing consent has to be as easy as giving it (art. 7.3 GDPR), so
 * it is one button away, on the consent screen and in every page of the area.
 */
#[Route('/familias')]
// No module gate on the class: with the families' area switched off, a family must still be able to read
// what it accepted and to leave. Only the consent screen, which leads into the area, follows the module.
final class FamilyPrivacyController extends AbstractController
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The families' version in force, open without signing in: the centre can send it to a family before
     * creating its account.
     */
    // Under /familias/entrar, which access_control already opens to anyone: no rule of its own needed.
    #[Route('/entrar/proteccion-datos', name: 'family_privacy_public', methods: ['GET'])]
    public function public(PrivacyNoticeRepository $notices): Response
    {
        return $this->render('family_area/privacy_public.html.twig', ['notice' => $notices->findCurrentForFamilies()]);
    }

    /**
     * The consent screen: the short first layer, the full text folded underneath, "Acepto" and "No acepto".
     * A family that already accepted the current version is sent on; with none published, the screen says
     * the area cannot be used yet.
     */
    #[Route('/consentimiento', name: 'family_consent', methods: ['GET'])]
    #[IsGranted(ModuleVoter::FAMILY_ACTIVITIES, statusCode: 404)]
    public function consent(#[CurrentUser] FamilyVolunteer $family, PrivacyNoticeRepository $notices, FamilyConsentRepository $consents): Response
    {
        $notice = $notices->findCurrentForFamilies();
        if (null !== $notice && $consents->hasConsented($family, $notice)) {
            return $this->redirectToRoute('family_home');
        }

        return $this->render('family_area/consent.html.twig', ['notice' => $notice]);
    }

    /**
     * Records that the family accepted the version shown to it. The version comes from the form, not from
     * "whatever is current now": if a newer one was published while it was reading, it accepted the text
     * it saw, and the gate shows it the new one next.
     */
    #[Route('/consentimiento', name: 'family_consent_give', methods: ['POST'])]
    #[IsGranted(ModuleVoter::FAMILY_ACTIVITIES, statusCode: 404)]
    public function give(Request $request, #[CurrentUser] FamilyVolunteer $family, PrivacyNoticeRepository $notices, FamilyConsentRepository $consents, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('family_consent', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // Only a families' version: the staff's text shares the table, and its id posted here must not pass.
        $notice = $notices->find($request->request->getInt('version'));
        if (!$notice instanceof FamilyPrivacyNotice) {
            throw $this->createNotFoundException();
        }

        // A double click, or a second device, is already done: the loser of a race reaches the unique
        // index, and nothing else uses the EntityManager in this request, so its closing does no harm.
        if (!$consents->hasConsented($family, $notice)) {
            try {
                $em->persist(new FamilyConsent($family, $notice, $this->clock->now()));
                $em->flush();
            } catch (UniqueConstraintViolationException) {
            }
        }
        return $this->redirectToRoute('family_home');
    }

    /**
     * Deletes the family's account, and with it its signups, its days available and its consents (all
     * hang from it with ON DELETE CASCADE). The same as the centre removing it, done by the family.
     *
     * No sign-out is needed: the families' firewall reloads the account on every request, so the next one
     * finds it gone and drops the session (and the "Recordarme" cookie with it). The page that says so is
     * rendered here, without a redirect that would need the account.
     */
    #[Route('/baja', name: 'family_leave', methods: ['POST'])]
    public function leave(Request $request, #[CurrentUser] FamilyVolunteer $family, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('family_leave', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $em->remove($family);
        $em->flush();

        return $this->render('family_area/left.html.twig');
    }
}
