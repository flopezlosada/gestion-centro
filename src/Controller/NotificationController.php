<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The user's notification inbox: their reminders and escalations, and a way to mark them read.
 */
final class NotificationController extends AbstractController
{
    #[Route('/avisos', name: 'notification_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, NotificationRepository $notifications): Response
    {
        return $this->render('notification/index.html.twig', [
            'notifications' => $notifications->findRecentFor($user),
        ]);
    }

    #[Route('/avisos/leer', name: 'notification_mark_read', methods: ['POST'])]
    public function markAllRead(Request $request, #[CurrentUser] User $user, NotificationRepository $notifications, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('mark_notifications_read', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        foreach ($notifications->findBy(['recipient' => $user, 'readAt' => null]) as $notification) {
            $notification->markRead();
        }
        $entityManager->flush();

        $this->addFlash('success', 'Avisos marcados como leídos.');

        return $this->redirectToRoute('notification_index');
    }
}
