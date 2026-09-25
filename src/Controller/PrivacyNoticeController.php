<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PrivacyNotice;
use App\Entity\PrivacyNoticeAck;
use App\Entity\User;
use App\EventSubscriber\PrivacyNoticeGateSubscriber;
use App\Repository\PrivacyNoticeAckRepository;
use App\Repository\PrivacyNoticeRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The data-protection information: the screen a person must get through once per version
 * ({@see PrivacyNoticeGateSubscriber}), and a public copy anyone can open from the sign-in page.
 */
#[Route('/proteccion-datos')]
final class PrivacyNoticeController extends AbstractController
{
    /**
     * The public copy of the version in force. Open without signing in: the information has to be
     * reachable BEFORE handing over any data, and from the sign-in page.
     */
    #[Route('', name: 'privacy_notice_public', methods: ['GET'])]
    public function public(PrivacyNoticeRepository $notices): Response
    {
        return $this->render('privacy_notice/public.html.twig', ['notice' => $notices->findCurrent()]);
    }

    /**
     * The reading screen: the short first layer, the full text folded underneath, and "Entendido".
     * Someone who already read the current version (or arrives when none exists) is sent on.
     */
    #[Route('/leer', name: 'privacy_notice_read', methods: ['GET'])]
    public function read(Request $request, #[CurrentUser] User $user, PrivacyNoticeRepository $notices, PrivacyNoticeAckRepository $acks): Response
    {
        $notice = $notices->findCurrent();
        $back = self::safeBack($request->query->getString('volver'));
        if (null === $notice || $acks->hasAcknowledged($user, $notice)) {
            return $this->redirect($back ?? $this->generateUrl('app_homepage'));
        }

        return $this->render('privacy_notice/read.html.twig', ['notice' => $notice, 'back' => $back]);
    }

    /**
     * Records that the person read the version shown to them and lets them through. The version comes
     * from the form, not from "whatever is current now": if a newer one was published while they were
     * reading, they acknowledged the text they saw, and the gate will show them the new one next.
     */
    #[Route('/entendido', name: 'privacy_notice_ack', methods: ['POST'])]
    public function acknowledge(Request $request, #[CurrentUser] User $user, PrivacyNoticeRepository $notices, PrivacyNoticeAckRepository $acks, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('privacy_notice_ack', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $notice = $notices->find($request->request->getInt('version'));
        if (!$notice instanceof PrivacyNotice) {
            throw $this->createNotFoundException();
        }

        // A double click, or a second tab, is simply already done. The check covers the usual case; two
        // requests racing past it both reach the unique index, and the loser is the same "already done"
        // (nothing else uses the EntityManager in this request, so its closing does no harm).
        if (!$acks->hasAcknowledged($user, $notice)) {
            try {
                $em->persist(new PrivacyNoticeAck($user, $notice, new \DateTimeImmutable()));
                $em->flush();
            } catch (UniqueConstraintViolationException) {
            }
        }
        $request->getSession()->set(PrivacyNoticeGateSubscriber::SESSION_KEY, $notice->getId());

        return $this->redirect(self::safeBack($request->request->getString('volver')) ?? $this->generateUrl('app_homepage'));
    }

    /**
     * Where to send the person afterwards, only if it is a path on this site: an absolute or
     * protocol-relative URL here would make the screen an open redirect.
     *
     * @param string $back the requested destination
     *
     * @return string|null the destination if it is a local path, null otherwise
     */
    private static function safeBack(string $back): ?string
    {
        return str_starts_with($back, '/') && !str_starts_with($back, '//') && !str_starts_with($back, '/\\') ? $back : null;
    }
}
