<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GuardiaCover;
use App\Entity\User;

/**
 * Avisa a los profesores a los que un cambio de guardia les afecta: al que pasa a cubrirla y, cuando
 * el cambio es manual, al que deja de cubrirla. Decide a quién avisar y qué decirle; la entrega (aviso
 * in-app + e-mail + push) la hace {@see NotificationDispatcher}, que comparte con el resto de
 * notificadores.
 *
 * La explicación que escribe coordinación al modificar una guardia viaja en estos avisos ($note): sin
 * eso quedaba solo en el histórico, que nadie abre, y el campo obligatorio de la pantalla "Modificar"
 * parecía burocracia — de ahí que el centro no entendiera para qué servía.
 */
final class GuardiaAssignmentNotifier
{
    /**
     * El recordatorio operativo del centro, que cierra el aviso de asignación. Aquí llega con antelación,
     * así que se enuncia como parte del trabajo que se acepta, no como un "hazlo ahora": ese lo manda
     * {@see GuardiaRaicesReminder} durante la propia hora. Solo acompaña a la asignación — al aviso de
     * "ya no tienes la guardia" no le pega, porque justamente ya no hay sesión que apuntar.
     */
    private const string RAICES_REMINDER = "\n\nRecuerda apuntar en RAICES las ausencias del alumnado de esa sesión.";

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {
    }

    /**
     * Notifica al profesor asignado a una guardia. No hace nada si la línea no tiene guardia asignada
     * (una asignación borrada no genera aviso). El cuerpo resume qué grupo/aula cubre y a quién sustituye,
     * y cierra con el recordatorio de RAICES ({@see self::RAICES_REMINDER}).
     *
     * @param GuardiaCover $cover the cover just assigned (already flushed)
     * @param string|null  $note  the coordinator's explanation, when the assignment comes from a manual change
     */
    public function notifyAssigned(GuardiaCover $cover, ?string $note = null): void
    {
        $recipient = $cover->getAssignedGuardia();
        if (null === $recipient) {
            return;
        }

        $title = sprintf('Nueva guardia: %s', $cover->getDate()->format('d/m/Y'));
        $body = sprintf(
            'Te han asignado una guardia el %s para cubrir a %s.',
            $cover->getDate()->format('d/m/Y'),
            self::whatIsCovered($cover),
        );

        // El recordatorio de RAICES va al FINAL, después de la explicación de coordinación: primero por
        // qué te toca a ti, y luego lo que hay que hacer al darla.
        $this->dispatcher->dispatch($recipient, 'guardia.assigned', $title, $body.self::explanation($note).self::RAICES_REMINDER);
    }

    /**
     * Notifica al profesor que YA NO tiene que hacer una guardia porque coordinación se la quitó a mano
     * (se la pasó a otro compañero o retiró la asignación). Sin este aviso el afectado seguiría contando
     * con ir a cubrir un grupo que ya no le toca.
     *
     * @param GuardiaCover $cover    the cover just changed (already flushed)
     * @param User         $previous the teacher who was covering it until now
     * @param string|null  $note     the coordinator's explanation of the change
     */
    public function notifyRelieved(GuardiaCover $cover, User $previous, ?string $note = null): void
    {
        $title = sprintf('Ya no tienes la guardia del %s', $cover->getDate()->format('d/m/Y'));
        $body = sprintf(
            'Coordinación ha cambiado la guardia del %s que ibas a cubrir (%s): ya no tienes que hacerla.',
            $cover->getDate()->format('d/m/Y'),
            self::whatIsCovered($cover),
        );

        $this->dispatcher->dispatch($previous, 'guardia.relieved', $title, $body.self::explanation($note));
    }

    /**
     * Describe en una frase qué se cubre: a quién se sustituye y, si constan, su grupo y aula.
     *
     * @param GuardiaCover $cover the cover
     *
     * @return string e.g. "Ana Pérez (grupo 3ºA) en el aula 12"
     */
    private static function whatIsCovered(GuardiaCover $cover): string
    {
        return sprintf(
            '%s%s%s',
            $cover->getAbsentTeacher()->getFullName(),
            null !== $cover->getGroupName() ? sprintf(' (grupo %s)', $cover->getGroupName()) : '',
            null !== $cover->getRoomName() ? sprintf(' en el aula %s', $cover->getRoomName()) : '',
        );
    }

    /**
     * El párrafo con la explicación de coordinación, o cadena vacía si no la hay (reparto automático).
     *
     * @param string|null $note the coordinator's explanation
     *
     * @return string the paragraph to append to the body
     */
    private static function explanation(?string $note): string
    {
        $note = null !== $note ? trim($note) : '';

        return '' !== $note ? sprintf("\n\nMotivo del cambio: %s", $note) : '';
    }
}
