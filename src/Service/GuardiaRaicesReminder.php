<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GuardiaCover;
use App\Entity\Notification;
use App\Repository\AcademicYearRepository;
use App\Repository\GuardiaCoverRepository;
use App\Repository\ScheduleEntryRepository;
use App\Util\SchoolYear;
use Psr\Log\LoggerInterface;

/**
 * Pushes the centre's operational reminder to whoever is covering a guardia RIGHT NOW: "entra en RAICES
 * y apunta las ausencias del alumnado de esta sesión". Meant to run every few minutes, off the same
 * sweep as the personal agenda reminders (see {@see \App\Service\Cron\Adapter\CentreCronManifest}).
 *
 * ### Why during the period and not before it
 * A guardia's reminder cannot be sent ahead of time like an appointment's: you can only note who is
 * missing once you are in the classroom. So the window is the period ITSELF — from its start time to
 * its end — which also makes the job self-healing: a cron run that is skipped, or an assignment made
 * after the bell (the usual case when a teacher calls in late), still gets its reminder on the next run
 * while the class is on. Once the period is over the reminder is dropped for good: a push at six in the
 * afternoon about a nine o'clock guardia is noise, not help.
 *
 * Delivery is push + in-app notice, never e-mail — same reasoning as the agenda reminder, and the policy
 * lives in {@see NotificationDispatcher::wantsEmail()} keyed by the "guardia.raices" kind. A teacher who
 * has not subscribed to push simply finds the notice in the bell: there is no separate "silence this"
 * setting to build, because the subscription IS the opt-in.
 *
 * ### Dónde aterriza el push (hueco conocido)
 * Al tocarlo se abre "Mis guardias", no la guardia concreta, a diferencia del resto de enlaces a una
 * guardia de la aplicación. La razón es de modelo, no un olvido: {@see \App\Entity\Notification} solo
 * sabe apuntar a un `?Task`, y colgarle una segunda clave ajena nullable para los covers convertiría el
 * "sujeto del aviso" en N columnas excluyentes. Lo correcto es un sujeto polimórfico (tipo + id), que la
 * aplicación YA tiene resuelto así en {@see \App\Entity\AuditLog}, y hacerlo bien obliga a mover de sitio
 * el enlace a tareas que ya existe: es un cambio aparte, no un apaño que estorbe al de verdad.
 * Mientras tanto se mitiga por partida doble: el cuerpo del aviso dice el aula y el grupo, y en "Mis
 * guardias" la que acaba de sonar es la protagonista mientras su hora no termine.
 *
 * ### On the sweep instant and time zones
 * Like {@see EventReminderNotifier}, this compares clock times, so the caller must pass a "now" in PHP's
 * default time zone: the period times come from the timetable as times of day and are pinned onto the
 * swept day here. Forcing another zone would shift every reminder by the offset.
 */
final readonly class GuardiaRaicesReminder
{
    public function __construct(
        private GuardiaCoverRepository $covers,
        private ScheduleEntryRepository $schedule,
        private AcademicYearRepository $years,
        private NotificationDispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Notifies every teacher whose guardia is under way and has not been reminded yet.
     *
     * The covers are stamped AFTER the notices are flushed and pushed, not before: if the process dies
     * in between, the worst case is the same reminder arriving twice within that class hour, which beats
     * losing it silently. Stamping first would trade a harmless duplicate for a reminder that never
     * comes — and this one carries an obligation (the roll in RAICES), unlike an agenda nudge.
     *
     * @param \DateTimeImmutable $now the sweep instant, in PHP's default time zone (see the class doc)
     *
     * @return int the number of reminders sent
     */
    public function sendDue(\DateTimeImmutable $now): int
    {
        $year = $this->years->findBySchoolYear(SchoolYear::current($now));
        $slotTimes = $this->schedule->slotTimes($year);
        if ([] === $slotTimes) {
            return 0; // sin horario importado no se sabe cuándo empieza ninguna hora: nada que barrer
        }

        $today = $now->setTime(0, 0);

        /** @var list<Notification> $notifications */
        $notifications = [];
        /** @var list<int> $sent */
        $sent = [];

        /** @var list<int> $unknownSlots covers whose period is not in the timetable (see the log below) */
        $unknownSlots = [];

        foreach ($this->covers->findRaicesRemindableOn($today) as $cover) {
            $times = $slotTimes[$cover->getSlotIndex()] ?? null;
            $recipient = $cover->getAssignedGuardia();
            $id = $cover->getId();
            if (null === $times) {
                $unknownSlots[] = $cover->getSlotIndex();
                continue;
            }
            // El profesor asignado no puede ser null aquí: findRaicesRemindableOn hace INNER JOIN sobre
            // assignedGuardia. La comprobación se queda porque el getter SÍ es nullable y sin ella el
            // análisis estático no puede saberlo — defensiva de tipos, no de datos.
            if (null === $recipient || null === $id || !$this->isUnderWay($times, $now)) {
                continue;
            }

            $notifications[] = $this->dispatcher->record(
                $recipient,
                'guardia.raices',
                'Apunta las ausencias en RAICES',
                $this->bodyFor($cover),
            );
            $sent[] = $id;
        }

        // Un tramo que el horario del curso no conoce no se puede situar en el reloj, así que su guardia
        // NUNCA recibirá el aviso — y como tampoco se marca, el barrido la volverá a mirar cada cinco
        // minutos sin efecto. Es un fallo silencioso salvo que se cuente: una línea por pasada (no por
        // cover, que inundaría el log), con los tramos concretos para poder ir a mirar el horario.
        if ([] !== $unknownSlots) {
            $this->logger->warning('Guardias sin aviso de RAICES: su tramo no está en el horario del curso', [
                'date' => $today->format('Y-m-d'),
                'slots' => array_values(array_unique($unknownSlots)),
                'covers' => \count($unknownSlots),
            ]);
        }

        $this->dispatcher->flushAndSend($notifications);
        $this->covers->markRaicesReminderSent($sent, $now);

        return \count($notifications);
    }

    /**
     * Whether a period is happening at the sweep instant. The timetable carries times of day with no
     * meaningful date, so both ends are pinned onto the swept day before comparing — reading them raw
     * would compare against whatever day they were parsed on.
     *
     * @param array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable} $times the period's times
     * @param \DateTimeImmutable                                             $now   the sweep instant
     *
     * @return bool true when the period has started and not yet ended
     */
    private function isUnderWay(array $times, \DateTimeImmutable $now): bool
    {
        return $now >= $this->onDayOf($times['startsAt'], $now) && $now <= $this->onDayOf($times['endsAt'], $now);
    }

    /**
     * The given time of day, on the day of the reference instant.
     *
     * @param \DateTimeImmutable $time      the time of day to pin
     * @param \DateTimeImmutable $reference the instant whose day to pin it onto
     *
     * @return \DateTimeImmutable the pinned instant
     */
    private function onDayOf(\DateTimeImmutable $time, \DateTimeImmutable $reference): \DateTimeImmutable
    {
        return $reference->setTime((int) $time->format('G'), (int) $time->format('i'), (int) $time->format('s'));
    }

    /**
     * The one-line body: where the guardia is, so the push is actionable without opening anything.
     *
     * @param GuardiaCover $cover the cover being reminded about
     *
     * @return string the body text
     */
    private function bodyFor(GuardiaCover $cover): string
    {
        $where = implode(' · ', array_filter([$cover->getRoomName(), $cover->getGroupName()]));

        return '' !== $where
            ? sprintf('Guardia en %s: apunta las ausencias del alumnado de esta sesión.', $where)
            : 'Apunta en RAICES las ausencias del alumnado de esta sesión.';
    }
}
