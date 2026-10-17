<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GuardiaCover;
use App\Entity\User;
use App\Repository\AcademicYearRepository;
use App\Repository\ScheduleEntryRepository;
use App\Util\CalendarDate;
use App\Util\SchoolYear;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Avisa a los profesores a los que un cambio de guardia les afecta: al que pasa a cubrirla y, cuando
 * el cambio es manual, al que deja de cubrirla. Decide a quién avisar y qué decirle; la entrega (aviso
 * in-app + e-mail + push) la hace {@see NotificationDispatcher}, que comparte con el resto de
 * notificadores.
 *
 * Lo que estos avisos NO llevan es el porqué del cambio. La explicación que escribe coordinación en
 * "Modificar guardia" es privada del equipo directivo (petición del centro) y se queda en el histórico:
 * a los profesores se les dice QUE su guardia ha cambiado, que es lo que necesitan para organizarse,
 * y quien coordina cuenta el motivo por su cuenta si toca.
 *
 * ### Una guardia que ya pasó no se avisa
 * Los dos avisos hablan en futuro («te han asignado», «ya no tienes que hacerla»), y es lo normal: se
 * asigna con antelación. Pero no siempre: un profesor que avisa a mediodía de que falta hoy deja las
 * guardias de la mañana ya vencidas, y coordinación corrige a posteriori quién cubrió de verdad. La
 * cobertura se guarda igual (es el histórico de jefatura de estudios); lo que no se manda es un aviso de
 * algo que ya terminó. Una guardia en curso SÍ se avisa: todavía hay alguien que tiene que ir.
 */
final class GuardiaAssignmentNotifier implements ResetInterface
{
    /**
     * El recordatorio operativo del centro, que cierra el aviso de asignación. Aquí llega con antelación,
     * así que se enuncia como parte del trabajo que se acepta, no como un "hazlo ahora": ese lo manda
     * {@see GuardiaRaicesReminder} durante la propia hora. Solo acompaña a la asignación — al aviso de
     * "ya no tienes la guardia" no le pega, porque justamente ya no hay sesión que apuntar.
     */
    private const string RAICES_REMINDER = "\n\nRecuerda apuntar en RAICES las ausencias del alumnado de esa sesión.";

    /**
     * Horas de cada tramo por curso, leídas una vez por petición: quien asigna en lote (repartir, abrir una
     * sustitución) avisa cover a cover, y sin esto serían dos consultas por guardia.
     *
     * @var array<string, array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}>>
     */
    private array $slotTimesBySchoolYear = [];

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly ScheduleEntryRepository $schedule,
        private readonly AcademicYearRepository $years,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Olvida las horas de los tramos entre peticiones, para que un proceso largo no avise con un horario
     * que ya se ha cambiado.
     */
    public function reset(): void
    {
        $this->slotTimesBySchoolYear = [];
    }

    /**
     * Notifica al profesor asignado a una guardia. No hace nada si la línea no tiene guardia asignada
     * (una asignación borrada no genera aviso) ni si el tramo ya terminó (ver la clase). El cuerpo resume
     * qué grupo/aula cubre y a quién sustituye, y cierra con el recordatorio de RAICES ({@see self::RAICES_REMINDER}).
     *
     * @param GuardiaCover $cover the cover just assigned (already flushed)
     */
    public function notifyAssigned(GuardiaCover $cover): void
    {
        $recipient = $cover->getAssignedGuardia();
        if (null === $recipient || $this->isOver($cover)) {
            return;
        }

        $title = sprintf('Nueva guardia: %s', $cover->getDate()->format('d/m/Y'));
        // Decir SI LLEVA TAREA cambia cuándo se puede resolver: sabiéndolo, quien cubre coge una del banco
        // la noche anterior desde el sofá; sin saberlo, se entera a las 8:20 delante del grupo.
        $body = sprintf(
            'Te han asignado una guardia el %s para cubrir a %s. %s',
            $cover->getDate()->format('d/m/Y'),
            self::whatIsCovered($cover),
            $cover->hasTask()
                ? 'El grupo tiene tarea.'
                : 'El grupo NO tiene tarea: puedes coger una del banco antes de la clase.',
        );

        $this->dispatcher->dispatch($recipient, 'guardia.assigned', $title, $body.self::RAICES_REMINDER);
    }

    /**
     * Notifica al profesor que YA NO tiene que hacer una guardia porque coordinación se la quitó a mano
     * (se la pasó a otro compañero o retiró la asignación). Sin este aviso el afectado seguiría contando
     * con ir a cubrir un grupo que ya no le toca. Si el tramo ya terminó no se avisa (ver la clase).
     *
     * @param GuardiaCover $cover    the cover just changed (already flushed)
     * @param User         $previous the teacher who was covering it until now
     */
    public function notifyRelieved(GuardiaCover $cover, User $previous): void
    {
        if ($this->isOver($cover)) {
            return;
        }

        $title = sprintf('Ya no tienes la guardia del %s', $cover->getDate()->format('d/m/Y'));
        $body = sprintf(
            'Coordinación ha cambiado la guardia del %s que ibas a cubrir (%s): ya no tienes que hacerla.',
            $cover->getDate()->format('d/m/Y'),
            self::whatIsCovered($cover),
        );

        $this->dispatcher->dispatch($previous, 'guardia.relieved', $title, $body);
    }

    /**
     * Si el tramo de la guardia ya ha terminado. La hora de fin sale del horario del curso al que
     * pertenece el día; si el horario no conoce el tramo, se toma el final de ese día, porque sin hora
     * cierta es preferible avisar de más un día que callarse una guardia que aún está por venir.
     *
     * @param GuardiaCover $cover the cover
     *
     * @return bool true once the period's end time has passed
     */
    private function isOver(GuardiaCover $cover): bool
    {
        $endsAt = $this->slotTimes($cover->getDate())[$cover->getSlotIndex()]['endsAt'] ?? null;
        $end = null !== $endsAt ? CalendarDate::at($cover->getDate(), $endsAt) : $cover->getDate()->setTime(23, 59, 59);

        return $this->clock->now() > $end;
    }

    /**
     * Las horas de los tramos del curso al que pertenece un día, leídas una sola vez por curso.
     *
     * @param \DateTimeImmutable $day the day whose course to read
     *
     * @return array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> slot index → times
     */
    private function slotTimes(\DateTimeImmutable $day): array
    {
        $schoolYear = SchoolYear::current($day);

        return $this->slotTimesBySchoolYear[$schoolYear] ??= $this->schedule->slotTimes($this->years->findBySchoolYear($schoolYear));
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
}
