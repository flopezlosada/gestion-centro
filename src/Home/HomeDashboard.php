<?php

declare(strict_types=1);

namespace App\Home;

use App\Agenda\AgendaEntry;
use App\Agenda\PersonalAgenda;
use App\Dashboard\CentreDashboard;
use App\Entity\GuardiaCover;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\AcademicYearRepository;
use App\Repository\GuardiaCoverRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Service\OrganizationHierarchy;
use App\Support\TaskStatus;
use App\Util\SchoolYear;

/**
 * Assembles the "qué me toca hoy" home view for a user: their next guardia (the single dark anchor of
 * the design), their institutional tasks due today or overdue, and their private agenda for today and
 * the coming week. Reuses {@see PersonalAgenda} for the task/event merge+bucket logic (single source)
 * and only splits the result back by kind, since the redesign shows tasks and personal events apart.
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
     *     nextGuardia: array{cover: GuardiaCover, startsAt: ?\DateTimeImmutable, minutesUntil: ?int}|null,
     *     upcomingGuardia: array{cover: GuardiaCover, startsAt: ?\DateTimeImmutable}|null,
     *     myTasks: AgendaEntry[],
     *     agendaEvents: AgendaEntry[]
     * }
     */
    public function baseFor(User $user, \DateTimeImmutable $today, \DateTimeImmutable $now): array
    {
        $buckets = $this->agenda->bucketsFor($user, $today);

        $isTask = static fn (AgendaEntry $e): bool => AgendaEntry::KIND_TASK === $e->kind;
        $isEvent = static fn (AgendaEntry $e): bool => AgendaEntry::KIND_EVENT === $e->kind;

        // Mis tareas = lo que aprieta hoy: vencidas primero, luego las de hoy (solo institucionales).
        // Es un vistazo corto: se recorta a unas pocas y "Ver todas" lleva al listado completo.
        $myTasks = \array_slice(array_values(array_filter([...$buckets['overdue'], ...$buckets['today']], $isTask)), 0, 5);
        // Mi agenda = mis citas/recordatorios privados de hoy y los próximos días.
        $agendaEvents = \array_slice(array_values(array_filter([...$buckets['today'], ...$buckets['week']], $isEvent)), 0, 4);

        [$next, $upcoming] = $this->guardia($user, $today, $now);

        return [
            'nextGuardia' => $next,
            'upcomingGuardia' => $upcoming,
            'myTasks' => $myTasks,
            'agendaEvents' => $agendaEvents,
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
            $pending = array_values(array_filter(
                $deptTasks,
                static fn (Task $t): bool => TaskStatus::SUBMITTED === $t->getStatus(),
            ));
            $modules['department'] = [
                'dept' => $dept,
                'people' => \count($this->usersRepo->findByUnit($dept)),
                'toValidate' => $ov['submitted'],
                'teamOpen' => $ov['pending'] + $ov['submitted'],
                'pending' => \array_slice($pending, 0, 3),
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
     * The user's next guardia today (the hero) and, only if there is none left today, the next future
     * one (for the "hoy no tienes guardia" strip). Times come from the slot index via the year's schedule.
     *
     * @return array{0: array{cover: GuardiaCover, startsAt: ?\DateTimeImmutable, minutesUntil: ?int}|null, 1: array{cover: GuardiaCover, startsAt: ?\DateTimeImmutable}|null}
     */
    private function guardia(User $user, \DateTimeImmutable $today, \DateTimeImmutable $now): array
    {
        $year = $this->years->findBySchoolYear(SchoolYear::current($today));
        $slotTimes = $this->schedule->slotTimes($year);

        // Primera guardia de hoy aún no terminada = la protagonista.
        foreach ($this->covers->findAssignedTo($user, $today) as $cover) {
            $times = $slotTimes[$cover->getSlotIndex()] ?? null;
            $startsAt = $times['startsAt'] ?? null;
            $endsAt = $times['endsAt'] ?? null;
            if (null === $endsAt || $endsAt >= $now) {
                return [[
                    'cover' => $cover,
                    'startsAt' => $startsAt,
                    'minutesUntil' => null !== $startsAt && $startsAt > $now
                        ? intdiv($startsAt->getTimestamp() - $now->getTimestamp(), 60)
                        : null,
                ], null];
            }
        }

        // Nada más hoy: la próxima futura, para la tira tranquila.
        $future = $this->covers->findUpcomingAssignedTo($user, $today->modify('+1 day'));
        if ([] !== $future) {
            $cover = $future[0];

            return [null, ['cover' => $cover, 'startsAt' => $slotTimes[$cover->getSlotIndex()]['startsAt'] ?? null]];
        }

        return [null, null];
    }
}
