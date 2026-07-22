<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Avisa a la persona a la que se le acaba de asignar una tarea (típicamente un superior creándola
 * para un subordinado): un aviso in-app más un e-mail, mismo idioma de notificación que el motor de
 * recordatorios ({@see TaskReminderNotifier}). El envío de e-mail va en un try/catch para que un
 * fallo de transporte nunca tumbe la creación de la tarea (el aviso in-app ya queda persistido).
 */
final class TaskAssignmentNotifier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.mailer_from%')]
        private readonly string $mailerFrom,
    ) {
    }

    /**
     * Notifica al responsable de una tarea recién creada. No hace nada si la tarea no tiene un
     * responsable resoluble o si ese responsable es el propio creador (crearte una tarea a ti mismo no
     * necesita aviso): así un docente que se apunta su propia tarea no se auto-envía un correo.
     *
     * @param Task $task    the freshly created (and flushed) task
     * @param User $creator the user who created it
     */
    public function notifyCreated(Task $task, User $creator): void
    {
        $recipient = $task->resolveResponsible();
        if (null === $recipient || $recipient === $creator) {
            return;
        }

        $notification = new Notification(
            $recipient,
            'task.assigned',
            sprintf('Nueva tarea: %s', $task->getTitle()),
            sprintf('%s te ha asignado una tarea. Vence el %s.', $creator->getFullName(), $task->getDueDate()->format('d/m/Y')),
            $task,
        );
        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        try {
            $this->mailer->send((new Email())
                ->from($this->mailerFrom)
                ->to($recipient->getEmail())
                ->subject($notification->getTitle())
                ->text((string) $notification->getBody()));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('No se pudo enviar el aviso de asignación de tarea por email', [
                'recipient' => $recipient->getEmail(),
                'exception' => $e,
            ]);
        }
    }
}
