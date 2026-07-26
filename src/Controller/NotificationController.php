<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Support\NotificationLink;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The user's notification inbox. Each notification is marked read only when its recipient OPENS it —
 * which also forwards them to what it is about — so the header bell keeps reflecting what they have
 * not yet looked at (opening the inbox alone does not clear it).
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

    /**
     * Opens a single notification: marks it read (once) and forwards the user to what it is about. The
     * destination comes from {@see NotificationLink} — the SAME source the Web Push payload uses — so a
     * push and its inbox row never disagree. Only its recipient may open it.
     */
    #[Route('/avisos/{id}', name: 'notification_open', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function open(Notification $notification, #[CurrentUser] User $user, EntityManagerInterface $entityManager, NotificationLink $link): Response
    {
        if ($notification->getRecipient() !== $user) {
            throw $this->createAccessDeniedException('Este aviso no es tuyo.');
        }

        if (!$notification->isRead()) {
            $notification->markRead();
            $entityManager->flush();
        }

        return $this->redirect($link->pathFor($notification));
    }
}
