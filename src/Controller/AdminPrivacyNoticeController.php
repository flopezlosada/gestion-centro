<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PrivacyNotice;
use App\Entity\User;
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
 * Publishing the data-protection information. Reserved to superusers, on the class like
 * {@see AdminAccessController}: publishing a version stops the whole staff at the door until they read
 * it, so it belongs with the screen that decides who gets in.
 */
#[Route('/admin/proteccion-datos')]
#[IsGranted('ROLE_ADMIN')]
final class AdminPrivacyNoticeController extends AbstractController
{
    /** Longest text accepted per field: a generous bound, only there to stop an accidental paste of a book. */
    private const MAX_LENGTH = 30000;

    /**
     * The version in force, how many people have read it, and the form to publish a new one.
     */
    #[Route('', name: 'admin_privacy_notice_index', methods: ['GET'])]
    public function index(PrivacyNoticeRepository $notices, PrivacyNoticeAckRepository $acks, UserRepository $users): Response
    {
        $current = $notices->findCurrent();

        return $this->render('admin/privacy_notice/index.html.twig', [
            'current' => $current,
            'readers' => null !== $current ? $acks->countActiveReaders($current) : 0,
            'activeUsers' => $users->count(['active' => true]),
        ]);
    }

    /**
     * Publishes a new version. It never edits the current one: each person's acknowledgement has to keep
     * pointing at the text they actually read, so any change — even a typo — is a new version that
     * everyone reads again.
     */
    #[Route('/publicar', name: 'admin_privacy_notice_publish', methods: ['POST'])]
    public function publish(Request $request, #[CurrentUser] User $user, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('privacy_notice_publish', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $summary = trim($request->request->getString('summary'));
        $body = trim($request->request->getString('body'));
        if ('' === $summary || '' === $body || mb_strlen($summary) > self::MAX_LENGTH || mb_strlen($body) > self::MAX_LENGTH) {
            $this->addFlash('error', 'Hacen falta los dos textos, el resumen y la información completa (hasta 30.000 caracteres cada uno).');

            return $this->redirectToRoute('admin_privacy_notice_index');
        }

        $em->persist(new PrivacyNotice($summary, $body, $user, new \DateTimeImmutable()));
        $em->flush();

        $this->addFlash('success', 'Versión publicada. A partir de ahora, cada persona la verá al abrir la aplicación y tendrá que pulsar «Entendido» para seguir.');

        return $this->redirectToRoute('admin_privacy_notice_index');
    }
}
