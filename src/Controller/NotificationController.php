<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationChannel;
use App\Enum\NotificationTopic;
use App\Repository\NotificationRepository;
use App\Support\NotificationLink;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
     * Where each person chooses how they want to be notified, section by section: on the phone, by
     * e-mail or both. The centre's complaint was that turning on the phone notices did not stop the
     * e-mails; this is the screen that decides it, and {@see \App\Service\NotificationDispatcher} obeys.
     *
     * Everyone sets their own and only their own — there is no id in the route on purpose.
     */
    #[Route('/avisos/ajustes', name: 'notification_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('notification_settings', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Token CSRF inválido.');
            }

            foreach (NotificationTopic::cases() as $topic) {
                $field = 'canal_'.$topic->value;
                // Solo se toca lo que VIENE en el envío. Un campo ausente no es "vuelve al defecto": es
                // una sección de la que este formulario no hablaba, y borrarla sería perder en silencio
                // un ajuste que la persona no ha tocado (pasaría con un POST parcial, o el día que se
                // añada una sección nueva y la pantalla se despliegue después).
                if (!$request->request->has($field)) {
                    continue;
                }

                // Un valor que no existe se lee como "sin elegir" en vez de rechazar el formulario
                // entero: nadie debería perder los cuatro ajustes buenos por uno manipulado.
                $user->setChannelFor($topic, NotificationChannel::tryFrom((string) $request->request->get($field)));
            }
            $entityManager->flush();
            $this->addFlash('success', 'Hecho. A partir de ahora los avisos te llegarán como has elegido.');

            return $this->redirectToRoute('notification_settings');
        }

        return $this->render('notification/settings.html.twig', [
            'topics' => NotificationTopic::cases(),
            'channels' => NotificationChannel::cases(),
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
