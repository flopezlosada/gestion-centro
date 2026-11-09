<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FamilyPrivacyNotice;
use App\Entity\StaffPrivacyNotice;
use App\Entity\User;
use App\Repository\FamilyConsentRepository;
use App\Repository\FamilyVolunteerRepository;
use App\Repository\PrivacyNoticeAckRepository;
use App\Repository\PrivacyNoticeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Publishing the data-protection information, the staff's and the families'. Reserved to superusers, on
 * the class like {@see AdminAccessController}: publishing a version stops a whole audience at the door
 * until they read it, so it belongs with the screen that decides who gets in.
 */
#[Route('/admin/proteccion-datos')]
#[IsGranted('ROLE_ADMIN')]
final class AdminPrivacyNoticeController extends AbstractController
{
    /** Longest text accepted per field: a generous bound, only there to stop an accidental paste of a book. */
    private const MAX_LENGTH = 30000;

    /** Value of `para` that switches the screen to the families' text; anything else is the staff's. */
    private const FAMILIES = 'familias';

    /**
     * The version in force for one audience, how many have read or accepted it, and the form to publish
     * a new one.
     */
    #[Route('', name: 'admin_privacy_notice_index', methods: ['GET'])]
    public function index(Request $request, PrivacyNoticeRepository $notices, PrivacyNoticeAckRepository $acks, UserRepository $users, FamilyConsentRepository $consents, FamilyVolunteerRepository $families): Response
    {
        if (self::FAMILIES === $request->query->getString('para')) {
            $current = $notices->findCurrentForFamilies();

            return $this->render('admin/privacy_notice/index.html.twig', [
                'families' => true,
                'current' => $current,
                'readers' => null !== $current ? $consents->countFor($current) : 0,
                'audienceSize' => $families->count(),
            ]);
        }

        $current = $notices->findCurrentForStaff();

        return $this->render('admin/privacy_notice/index.html.twig', [
            'families' => false,
            'current' => $current,
            'readers' => null !== $current ? $acks->countActiveReaders($current) : 0,
            'audienceSize' => $users->count(['active' => true]),
        ]);
    }

    /**
     * Publishes a new version for one audience. It never edits the current one: each acknowledgement or
     * consent has to keep pointing at the text that was actually shown, so any change — even a typo — is
     * a new version that everyone in that audience reads again.
     */
    #[Route('/publicar', name: 'admin_privacy_notice_publish', methods: ['POST'])]
    public function publish(Request $request, #[CurrentUser] User $user, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('privacy_notice_publish', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $forFamilies = self::FAMILIES === $request->request->getString('para');
        $back = $this->redirectToRoute('admin_privacy_notice_index', $forFamilies ? ['para' => self::FAMILIES] : []);
        $summary = trim($request->request->getString('summary'));
        $body = trim($request->request->getString('body'));
        if ('' === $summary || '' === $body || mb_strlen($summary) > self::MAX_LENGTH || mb_strlen($body) > self::MAX_LENGTH) {
            $this->addFlash('error', 'Hacen falta los dos textos, el resumen y la información completa (hasta 30.000 caracteres cada uno).');

            return $back;
        }

        $now = new \DateTimeImmutable();
        $em->persist($forFamilies ? new FamilyPrivacyNotice($summary, $body, $user, $now) : new StaffPrivacyNotice($summary, $body, $user, $now));
        $em->flush();

        $this->addFlash('success', $forFamilies
            ? 'Versión publicada. Cada familia la verá al entrar y tendrá que aceptarla para seguir usando su espacio.'
            : 'Versión publicada. A partir de ahora, cada persona la verá al abrir la aplicación y tendrá que pulsar «Entendido» para seguir.');

        return $back;
    }
}
