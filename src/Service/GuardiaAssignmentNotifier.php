<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GuardiaCover;
use App\Entity\Notification;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Avisa al profesor al que se le acaba de asignar (o reasignar) una guardia: un aviso in-app más un
 * e-mail, mismo patrón que {@see TaskAssignmentNotifier}. El envío de e-mail va en un try/catch para
 * que un fallo de transporte (p. ej. mailer sin configurar en local) nunca tumbe la asignación: el
 * aviso in-app ya queda persistido.
 */
final class GuardiaAssignmentNotifier
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
     * Notifica al profesor asignado a una guardia. No hace nada si la línea no tiene guardia asignada
     * (una asignación borrada no genera aviso). El cuerpo resume qué grupo/aula cubre y a quién sustituye.
     *
     * @param GuardiaCover $cover the cover just assigned (already flushed)
     */
    public function notifyAssigned(GuardiaCover $cover): void
    {
        $recipient = $cover->getAssignedGuardia();
        if (null === $recipient) {
            return;
        }

        $title = sprintf('Nueva guardia: %s', $cover->getDate()->format('d/m/Y'));
        $body = sprintf(
            'Te han asignado una guardia el %s para cubrir a %s%s%s.',
            $cover->getDate()->format('d/m/Y'),
            $cover->getAbsentTeacher()->getFullName(),
            null !== $cover->getGroupName() ? sprintf(' (grupo %s)', $cover->getGroupName()) : '',
            null !== $cover->getRoomName() ? sprintf(' en el aula %s', $cover->getRoomName()) : '',
        );

        $notification = new Notification($recipient, 'guardia.assigned', $title, $body);
        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        try {
            $this->mailer->send((new Email())
                ->from($this->mailerFrom)
                ->to($recipient->getEmail())
                ->subject($title)
                ->text($body));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('No se pudo enviar el aviso de asignación de guardia por email', [
                'recipient' => $recipient->getEmail(),
                'exception' => $e,
            ]);
        }
    }
}
