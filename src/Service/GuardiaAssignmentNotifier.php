<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GuardiaCover;

/**
 * Avisa al profesor al que se le acaba de asignar (o reasignar) una guardia. Decide a quién avisar y
 * qué decirle; la entrega (aviso in-app + e-mail + push) la hace {@see NotificationDispatcher}, que
 * comparte con el resto de notificadores.
 */
final class GuardiaAssignmentNotifier
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {
    }

    /**
     * Notifica al profesor asignado a una guardia. No hace nada si la línea no tiene guardia asignada
     * (una asignación borrada no genera aviso). El cuerpo resume qué grupo/aula cubre y a quién sustituye,
     * y cierra con el recordatorio operativo del centro (apuntar las ausencias del alumnado en RAICES):
     * aquí llega con antelación, así que se enuncia como parte del trabajo que se acepta, no como un
     * "hazlo ahora" — ese lo manda {@see GuardiaRaicesReminder} durante la propia hora.
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
            'Te han asignado una guardia el %s para cubrir a %s%s%s. Recuerda apuntar en RAICES las ausencias del alumnado de esa sesión.',
            $cover->getDate()->format('d/m/Y'),
            $cover->getAbsentTeacher()->getFullName(),
            null !== $cover->getGroupName() ? sprintf(' (grupo %s)', $cover->getGroupName()) : '',
            null !== $cover->getRoomName() ? sprintf(' en el aula %s', $cover->getRoomName()) : '',
        );

        $this->dispatcher->dispatch($recipient, 'guardia.assigned', $title, $body);
    }
}
