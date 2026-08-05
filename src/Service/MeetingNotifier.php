<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Meeting;
use App\Entity\User;

/**
 * Avisa a la gente de una reunión: la convocatoria, un cambio de día u hora, la cancelación, el acta al
 * publicarse y las observaciones a un acta publicada. Decide a QUIÉN avisar y QUÉ decirle; la entrega
 * (aviso in-app + e-mail + push) la hace {@see NotificationDispatcher}, compartida con el resto de
 * notificadores.
 *
 * Nunca se avisa a quien convoca de lo que acaba de hacer. La excepción es {@see notifyRemark()}, que va
 * dirigido justo a quien manda en el acta: ahí quien convoca es destinatario, no autor.
 */
final readonly class MeetingNotifier
{
    public function __construct(private NotificationDispatcher $dispatcher)
    {
    }

    /**
     * Convocatoria: avisa a las personas indicadas de que están convocadas. Se le pasan los convocados
     * NUEVOS (los que devuelve {@see Meeting::syncAttendees()}), no la lista entera, para que editar el
     * orden del día no vuelva a convocar a quien ya lo estaba.
     *
     * @param Meeting    $meeting the meeting
     * @param list<User> $people  the people to tell (typically the newly convened ones)
     */
    public function notifyConvened(Meeting $meeting, array $people): void
    {
        $this->tell(
            $meeting,
            $people,
            'meeting.convened',
            \sprintf('Reunión: %s', $meeting->getTitle()),
            \sprintf(
                '%s te ha convocado%s. Es %s.',
                $meeting->getConvener()?->getFullName() ?? 'El centro',
                null !== $meeting->getProject() ? ' a una reunión de '.$meeting->getProject()->getName() : '',
                $this->when($meeting),
            ),
        );
    }

    /**
     * Cambio de convocatoria: avisa a los ya convocados de que la reunión se mueve. Solo se llama cuando
     * cambia el CUÁNDO o el DÓNDE — lo que te hace llegar tarde o al sitio equivocado.
     *
     * @param Meeting    $meeting the meeting, already updated
     * @param list<User> $people  the people already convened
     */
    public function notifyRescheduled(Meeting $meeting, array $people): void
    {
        $this->tell(
            $meeting,
            $people,
            'meeting.rescheduled',
            \sprintf('Cambio de reunión: %s', $meeting->getTitle()),
            \sprintf('La reunión cambia: ahora es %s.', $this->when($meeting)),
        );
    }

    /**
     * Cancelación: avisa a los convocados de que la reunión ya no se celebra. Hay que llamarlo ANTES de
     * borrarla — después no queda de dónde sacar los convocados, y una reunión que desaparece en silencio
     * hace que alguien se presente en una sala vacía.
     *
     * @param Meeting    $meeting the meeting about to be deleted
     * @param list<User> $people  the people convened
     */
    public function notifyCancelled(Meeting $meeting, array $people): void
    {
        $this->tell(
            $meeting,
            $people,
            'meeting.cancelled',
            \sprintf('Reunión cancelada: %s', $meeting->getTitle()),
            \sprintf('Se cancela la reunión del %s a las %s.', $meeting->getStartAt()->format('d/m/Y'), $meeting->getStartAt()->format('H:i')),
        );
    }

    /**
     * Acta disponible: avisa a los convocados de que ya pueden leer lo acordado.
     *
     * @param Meeting    $meeting the meeting with its minutes attached
     * @param list<User> $people  the people convened
     */
    public function notifyMinutes(Meeting $meeting, array $people): void
    {
        $this->tell(
            $meeting,
            $people,
            'meeting.minutes',
            \sprintf('Acta de: %s', $meeting->getTitle()),
            \sprintf('Ya puedes leer el acta de la reunión del %s.', $meeting->getStartAt()->format('d/m/Y')),
        );
    }

    /**
     * Observación al acta: avisa a quien puede hacer algo con ella — quien la levanta y quien convocó, que
     * son las dos personas que pueden corregirla ({@see \App\Service\MeetingAccess::canWriteMinutes()}).
     *
     * NO se avisa al resto del grupo convocado: una puntualización es una petición de corrección dirigida a
     * quien firma el acta, no un tablón. Si el acta se corrige, lo que llega a todo el mundo es el acta
     * corregida, que es lo único que queda en el registro.
     *
     * @param Meeting $meeting the meeting whose acta was remarked on
     * @param User    $author  who raised it (never notified: they just wrote it)
     */
    public function notifyRemark(Meeting $meeting, User $author): void
    {
        // tell() ya salta a quien convoca, y aquí es justamente uno de los destinatarios: se entrega en
        // directo para no depender de ese filtro.
        $recipients = [];
        foreach ([$meeting->minutesKeeper(), $meeting->getConvener()] as $person) {
            if (null !== $person && $person !== $author && !\in_array($person, $recipients, true)) {
                $recipients[] = $person;
            }
        }
        if ([] === $recipients) {
            return;
        }

        $notifications = [];
        foreach ($recipients as $person) {
            $notifications[] = $this->dispatcher->record(
                $person,
                'meeting.remark',
                \sprintf('Observación al acta: %s', $meeting->getTitle()),
                \sprintf('%s ha puntualizado el acta de la reunión del %s.', $author->getFullName(), $meeting->getStartAt()->format('d/m/Y')),
            );
        }
        $this->dispatcher->flushAndSend($notifications);
    }

    /**
     * Registra y entrega un aviso por persona, saltándose a quien convoca. Un único flush para todo el
     * lote ({@see NotificationDispatcher::record()} + {@see NotificationDispatcher::flushAndSend()}), que
     * es lo que evita un flush por convocado.
     *
     * @param Meeting    $meeting the meeting the notice is about
     * @param list<User> $people  the candidate recipients
     * @param string     $kind    the machine kind ("meeting.*")
     * @param string     $title   the notice title
     * @param string     $body    the notice body
     */
    private function tell(Meeting $meeting, array $people, string $kind, string $title, string $body): void
    {
        $notifications = [];
        foreach ($people as $person) {
            if ($person === $meeting->getConvener()) {
                continue;
            }
            $notifications[] = $this->dispatcher->record($person, $kind, $title, $body);
        }

        if ([] !== $notifications) {
            $this->dispatcher->flushAndSend($notifications);
        }
    }

    /**
     * Cuándo y dónde, en un trozo de frase ("el 12/09/2026 a las 14:00, en la sala de profesores"), para
     * que las dos frases que lo usan lo digan igual.
     *
     * @param Meeting $meeting the meeting
     *
     * @return string the human fragment
     */
    private function when(Meeting $meeting): string
    {
        $when = \sprintf('el %s a las %s', $meeting->getStartAt()->format('d/m/Y'), $meeting->getStartAt()->format('H:i'));

        return null !== $meeting->getPlace() ? $when.', en '.$meeting->getPlace() : $when;
    }
}
