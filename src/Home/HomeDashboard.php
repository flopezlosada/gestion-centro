<?php

declare(strict_types=1);

namespace App\Home;

use App\Agenda\AgendaEntry;
use App\Agenda\PersonalAgenda;
use App\Dashboard\CentreDashboard;
use App\Entity\GuardiaCover;
use App\Entity\Task;
use App\Entity\User;
use App\Guardia\TeacherGuardiaDay;
use App\Repository\AcademicYearRepository;
use App\Repository\GuardiaCoverRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Service\OrganizationHierarchy;
use App\Service\TaskWorkflow;
use App\Support\TaskStatus;
use App\Util\SchoolYear;

/**
 * Assembles the "qué me toca hoy" home view for a user: every guardia they cover today (the next one as
 * the single dark anchor of the design, the rest listed under it), their institutional tasks due today
 * or overdue, and their private agenda for today and the coming week. Reuses {@see PersonalAgenda} for
 * the task/event merge+bucket logic (single source) and only splits the result back by kind, since the
 * redesign shows tasks and personal events apart.
 *
 * Guardias ride along the two blocks instead of joining one of them: the day's blocks are "Con hora"
 * (private appointments) and "Por hacer" (checklist), and a guardia is neither private nor tickable.
 * They keep their own anchor above the blocks and, for later days, appear as a third kind of
 * {@see AgendaEntry} in "Próximos 7 días" — where the point is only "what is coming".
 *
 * Role-aware modules (mi departamento, el centro, guardias de hoy) are layered on top elsewhere; this
 * builds the base every role shares.
 */
final readonly class HomeDashboard
{
    public function __construct(
        private PersonalAgenda $agenda,
        private GuardiaCoverRepository $covers,
        private ScheduleEntryRepository $schedule,
        private AcademicYearRepository $years,
        private TaskRepository $tasks,
        private CentreDashboard $centre,
        private OrganizationHierarchy $hierarchy,
        private UserRepository $usersRepo,
        private TaskWorkflow $workflows,
        private TeacherGuardiaDay $guardiaDay,
    ) {
    }

    /**
     * The base home view-model shared by every role.
     *
     * @param User               $user  the viewer
     * @param \DateTimeImmutable $today the reference day (midnight)
     * @param \DateTimeImmutable $now   the current instant (for "en X min" and done detection)
     *
     * @return array{
     *     nextGuardia: array{cover: GuardiaCover, done: bool, startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable, minutesUntil: ?int}|null,
     *     otherGuardiasToday: list<array{cover: GuardiaCover, done: bool, startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable, minutesUntil: ?int}>,
     *     guardiasTodayCount: int,
     *     upcomingGuardia: array{cover: GuardiaCover, startsAt: ?\DateTimeImmutable}|null,
     *     timedToday: AgendaEntry[],
     *     todos: AgendaEntry[],
     *     upcoming: AgendaEntry[]
     * }
     */
    public function baseFor(User $user, \DateTimeImmutable $today, \DateTimeImmutable $now): array
    {
        $buckets = $this->agenda->bucketsFor($user, $today);

        $isTask = static fn (AgendaEntry $e): bool => AgendaEntry::KIND_TASK === $e->kind;
        // Una cita: un evento CON hora (no un recordatorio de todo el día). Va al bloque "Con hora".
        $isTimedEvent = static fn (AgendaEntry $e): bool => AgendaEntry::KIND_EVENT === $e->kind && null !== $e->event && !$e->event->isAllDay();
        // Un "por hacer": una tarea del centro (fecha límite) o un recordatorio personal sin hora.
        $isTodo = static fn (AgendaEntry $e): bool => $isTask($e) || (AgendaEntry::KIND_EVENT === $e->kind && null !== $e->event && $e->event->isAllDay());

        // "Con hora": tu horario de HOY — las citas con hora, en orden de reloj (los buckets ya vienen
        // ordenados por su instante). Una cita no se "hace con casilla"; se cumple estando.
        $timedToday = array_values(array_filter($buckets['today'], $isTimedEvent));

        // "Por hacer": lo pendiente en tu plato hoy, como checklist — vencidas (de cualquier tipo) primero,
        // luego las tareas de hoy y los recordatorios sin hora. Las citas con hora NO entran aquí.
        $todos = \array_slice([
            ...$buckets['overdue'],
            ...array_values(array_filter($buckets['today'], $isTodo)),
        ], 0, 8);

        $guardias = $this->guardias($user, $today, $now);

        // "Próximos 7 días": un vistazo compacto y tipado (tareas, eventos y guardias juntos por día,
        // en orden); lo lejano vive en el Calendario, al que se sale desde el pie de la sección.
        $upcoming = [...$buckets['week'], ...$guardias['weekEntries']];
        usort($upcoming, static fn (AgendaEntry $a, AgendaEntry $b): int => $a->date <=> $b->date);
        $upcoming = \array_slice($upcoming, 0, 6);

        return [
            'nextGuardia' => $guardias['next'],
            'otherGuardiasToday' => $guardias['others'],
            'guardiasTodayCount' => $guardias['todayCount'],
            'upcomingGuardia' => $guardias['upcomingGuardia'],
            'timedToday' => $timedToday,
            'todos' => $todos,
            'upcoming' => $upcoming,
            'roleSubtitle' => $this->roleSubtitle($user),
        ];
    }

    /**
     * Role-aware modules layered on top of the base, one per hat the user wears. A plain teacher gets
     * none (empty array); a head of department gets "department"; direction/leadership gets "centre".
     * The task figures come from a single {@see CentreDashboard::overview()} pass over the year's tasks.
     *
     * @return array{
     *     guardiasToday?: array{uncovered: int},
     *     department?: array{dept: \App\Entity\Department, people: int, toValidate: int, teamOpen: int, pending: Task[]},
     *     centre?: array{pct: int, finalized: int, total: int, toValidate: int, overdue: int}
     * }
     */
    public function modulesFor(User $user, \DateTimeImmutable $today, bool $isGuardiaCoordinator = false): array
    {
        $dept = $this->hierarchy->commandedDepartment($user);
        $commandsSchool = $this->hierarchy->commandsWholeSchool($user);
        if (null === $dept && !$commandsSchool && !$isGuardiaCoordinator) {
            return [];
        }

        $modules = [];
        // Coordinación de guardias: las ausencias de HOY todavía sin cubrir.
        if ($isGuardiaCoordinator) {
            $modules['guardiasToday'] = ['uncovered' => $this->covers->countUnassignedOn($today)];
        }
        // Los módulos de tareas necesitan las tareas del curso; si no toca ninguno, evita el fetch.
        if (null === $dept && !$commandsSchool) {
            return $modules;
        }

        // Un solo fetch del curso; cada módulo agrega sobre la misma lista.
        $allTasks = $this->tasks->findBySchoolYear(SchoolYear::current($today));

        if (null !== $dept) {
            $deptTasks = array_values(array_filter(
                $allTasks,
                static fn (Task $t): bool => $t->getUnit()?->getId() === $dept->getId(),
            ));
            $ov = $this->centre->overview($deptTasks, $today);
            // "Por validar" es lo que ESTE usuario puede validar de verdad, y eso lo decide el workflow
            // (TaskValidationGuardSubscriber): nadie valida su propia tarea, aunque mande en su
            // departamento. Contar todas las entregadas del depto metía las suyas y prometía un trabajo
            // que la ficha de la tarea iba a negar — una jefa de departamento supera al rol Tutor/a de su
            // propia tarea, así que colaba.
            $toValidate = array_values(array_filter(
                $deptTasks,
                fn (Task $t): bool => TaskStatus::SUBMITTED === $t->getStatus() && $this->workflows->for($t)->can($t, 'validate'),
            ));
            $modules['department'] = [
                'dept' => $dept,
                'people' => \count($this->usersRepo->findByUnit($dept)),
                'toValidate' => \count($toValidate),
                // "Abierto del equipo" sí incluye lo propio: es la carga del departamento, no un trabajo
                // pendiente de este usuario.
                'teamOpen' => $ov['pending'] + $ov['submitted'],
                'pending' => \array_slice($toValidate, 0, 3),
            ];
        }

        if ($commandsSchool) {
            $ov = $this->centre->overview($allTasks, $today);
            $modules['centre'] = [
                'pct' => $ov['pctFinalized'],
                'finalized' => $ov['finalized'],
                'total' => $ov['total'],
                'toValidate' => $ov['submitted'],
                'overdue' => $ov['overdue'],
            ];
        }

        return $modules;
    }

    /**
     * A short "who am I here" line for the greeting: the names of the user's ranked roles (direction,
     * leadership, head of department) joined with the department. Null for a plain teacher, whose home
     * needs no role line.
     */
    private function roleSubtitle(User $user): ?string
    {
        $roles = [];
        foreach ($user->getAssignedRoles() as $role) {
            if (null !== $role->getHierarchyLevel()) {
                $roles[] = $role->getName();
            }
        }
        if ([] === $roles) {
            return null;
        }
        if (null !== $user->getUnit()) {
            $roles[] = $user->getUnit()->getName();
        }

        return implode(' · ', $roles);
    }

    /**
     * Every guardia the user has coming, split into what each part of Inicio needs — the centre asked
     * for ALL of them to show up in the agenda, not just the next one:
     *  - `next`: the first one today not yet over, the hero;
     *  - `others`: the REST of today, listed under the hero (the ones already over included, flagged
     *    done — a day with three guardias must read as three);
     *  - `todayCount`: how many there are today at all, so the "hoy no tienes guardia" strip can tell
     *    "no tienes ninguna" apart from "ya las has hecho todas";
     *  - `upcomingGuardia`: the next future one, for that same strip, at ANY distance (a guardia three
     *    weeks out still deserves the mention when today is clear);
     *  - `weekEntries`: the ones in the next 7 days as agenda entries, for "Próximos 7 días".
     *
     * One query feeds all five: the covers assigned from today on, split by day in PHP. Period times
     * come from the slot index via the course's timetable.
     *
     * @return array{
     *     next: array{cover: GuardiaCover, done: bool, startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable, minutesUntil: ?int}|null,
     *     others: list<array{cover: GuardiaCover, done: bool, startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable, minutesUntil: ?int}>,
     *     todayCount: int,
     *     upcomingGuardia: array{cover: GuardiaCover, startsAt: ?\DateTimeImmutable}|null,
     *     weekEntries: list<AgendaEntry>
     * }
     */
    private function guardias(User $user, \DateTimeImmutable $today, \DateTimeImmutable $now): array
    {
        $year = $this->years->findBySchoolYear(SchoolYear::current($today));
        $slotTimes = $this->schedule->slotTimes($year);

        $todayKey = $today->format('Y-m-d');
        $weekKey = $today->modify('+7 days')->format('Y-m-d');

        $todayCovers = [];
        $future = [];
        foreach ($this->covers->findUpcomingAssignedTo($user, $today) as $cover) {
            if ($cover->getDate()->format('Y-m-d') === $todayKey) {
                $todayCovers[] = $cover;
            } else {
                $future[] = $cover;
            }
        }

        // El MISMO view-model que /guardias/mias, para que las dos pantallas no puedan discrepar sobre
        // cuál es "tu próxima guardia" ni sobre cuáles ya han pasado.
        $day = $this->guardiaDay->forDay($todayCovers, $slotTimes, $now);
        $next = null !== $day['next'] ? $day['items'][$day['next']] : null;
        $others = [];
        foreach ($day['items'] as $i => $item) {
            if ($i !== $day['next']) {
                $others[] = $item;
            }
        }

        $weekEntries = [];
        foreach ($future as $cover) {
            if ($cover->getDate()->format('Y-m-d') > $weekKey) {
                break; // la consulta viene en orden cronológico: a partir de aquí ya es "lo lejano"
            }
            $weekEntries[] = AgendaEntry::fromGuardia($cover, $this->startOf($cover, $slotTimes));
        }

        return [
            'next' => $next,
            'others' => $others,
            'todayCount' => $day['counts']['assigned'],
            'upcomingGuardia' => (null === $next && [] !== $future)
                ? ['cover' => $future[0], 'startsAt' => $this->startOf($future[0], $slotTimes)]
                : null,
            'weekEntries' => $weekEntries,
        ];
    }

    /**
     * The instant a cover's period starts, on the cover's OWN day.
     *
     * {@see ScheduleEntryRepository::slotTimes()} carries clock times parsed with no date, so they land
     * on whatever "today" was when they were built. Reading them straight for a cover on another day
     * would date every future guardia today — which sorts it into the wrong place in "Próximos 7 días"
     * and prints the wrong day next to it. So take the time of day from the timetable and the date from
     * the cover.
     *
     * @param GuardiaCover                                                                $cover     the cover
     * @param array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> $slotTimes period times by slot index
     *
     * @return \DateTimeImmutable|null the start instant, or null with no timetable for that period
     */
    private function startOf(GuardiaCover $cover, array $slotTimes): ?\DateTimeImmutable
    {
        $startsAt = $slotTimes[$cover->getSlotIndex()]['startsAt'] ?? null;

        return null !== $startsAt
            ? $cover->getDate()->setTime((int) $startsAt->format('G'), (int) $startsAt->format('i'))
            : null;
    }
}
