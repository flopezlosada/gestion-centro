<?php

declare(strict_types=1);

namespace App\Home;

use App\Agenda\AgendaEntry;
use App\Agenda\PersonalAgenda;
use App\Dashboard\CentreDashboard;
use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\GuardiaCover;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\Weekday;
use App\Guardia\TeacherGuardiaDay;
use App\Repository\AcademicYearRepository;
use App\Repository\BreakDutyAssignmentRepository;
use App\Repository\BreakDutyGapRepository;
use App\Repository\GuardiaCoverRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\TaskRepository;
use App\Repository\TimeSlotRepository;
use App\Repository\UserRepository;
use App\Service\OrganizationHierarchy;
use App\Service\SchoolCalendar;
use App\Service\TaskWorkflow;
use App\Support\TaskStatus;
use App\Util\SchoolYear;

/**
 * Assembles the "qué me toca hoy" home view for a user: the guardia they cover next as the single dark
 * anchor, what they still have to do, the clock of their day, and a preview of the coming week. Reuses
 * {@see PersonalAgenda} for the task/event merge+bucket logic (single source) and splits the result by
 * what each block of the screen answers.
 *
 * The split is by NATURE, not by date, which is what keeps the same thing from appearing twice:
 *  - "Por hacer" gets what you WORK ON — tasks with a deadline and undated personal reminders — with the
 *    overdue ones collapsed into a single summary line instead of a wall of red rows;
 *  - "Tu día" gets what you TURN UP TO today, on the clock: guardias, convened meetings and timed
 *    private appointments in one timeline, each marked with its own kind;
 *  - "Próximos 7 días" gets the same "turn up to" kinds, for the days after today.
 *
 * Guardias DO belong in "Tu día", unlike in the previous design: back then the block was "Con hora", a
 * malva box that meant PRIVATE in the design system, and a guardia is imposed by the centre. The box is
 * gone — privacy is now marked per row — so the reason to keep them out went with it. They still keep
 * their own anchor above, because the next one is the single most time-critical thing on the screen.
 *
 * Role-aware modules (mi departamento, el centro, guardias de hoy) are layered on top elsewhere; this
 * builds the base every role shares.
 */
final readonly class HomeDashboard
{
    /**
     * Cuántas filas de "Por hacer" se pintan. Tres, no ocho: el bloque tiene que caber por encima del
     * pliegue junto al resto de la pantalla, y lo que no cabe no se esconde — se cuenta en la cabecera y
     * en el pie, que llevan a la lista completa.
     */
    private const int TODOS_SHOWN = 3;

    /** Cuántas filas se pintan en "Próximos 7 días". Es un anticipo, no la semana entera: eso es el Calendario. */
    private const int UPCOMING_SHOWN = 6;

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
        private BreakDutyAssignmentRepository $breakDuties,
        private BreakDutyGapRepository $breakGaps,
        private TimeSlotRepository $timeSlots,
        private SchoolCalendar $calendar,
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
     *     nextGuardia: array{cover: GuardiaCover, done: bool, startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable, minutesUntil: ?int, inProgress: bool}|null,
     *     guardiasTodayCount: int,
     *     upcomingGuardia: array{cover: GuardiaCover, startsAt: ?\DateTimeImmutable}|null,
     *     breakDutiesToday: list<array{duty: BreakDutyAssignment, entry: AgendaEntry, startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable}>,
     *     dayTimeline: list<array{entry: AgendaEntry, startsAt: ?\DateTimeImmutable, minutesUntil: ?int, state: string}>,
     *     todos: AgendaEntry[],
     *     todosTotal: int,
     *     overdueCount: int,
     *     overdueOldest: ?\DateTimeImmutable,
     *     upcoming: AgendaEntry[],
     *     upcomingTotal: int,
     *     roleSubtitle: ?string
     * }
     */
    public function baseFor(User $user, \DateTimeImmutable $today, \DateTimeImmutable $now): array
    {
        // El curso se resuelve UNA vez y viaja a los dos bloques que lo necesitan (las guardias de
        // sustitución y el recreo): son dos lecturas del mismo dato en la pantalla más visitada.
        $year = $this->years->findBySchoolYear(SchoolYear::current($today));
        $buckets = $this->agenda->bucketsFor($user, $today);
        $guardias = $this->guardias($user, $year, $today, $now);
        $breakDuties = $this->breakDutiesOn($user, $year, $today);

        $isMeeting = static fn (AgendaEntry $e): bool => AgendaEntry::KIND_MEETING === $e->kind;
        // Un "por hacer": una tarea del centro (fecha límite) o un recordatorio personal sin hora. Lo que
        // se trabaja y se tacha, frente a lo que se atiende a una hora.
        $isTodo = static fn (AgendaEntry $e): bool => AgendaEntry::KIND_TASK === $e->kind
            || (AgendaEntry::KIND_EVENT === $e->kind && null !== $e->event && $e->event->isAllDay());

        // ---- "Por hacer" -------------------------------------------------------------------------
        // Las TAREAS DEL CENTRO fuera de plazo no se pintan fila a fila: se resumen en UNA línea con el
        // total y la más antigua. Con el arrastre de un curso, un muro de ocho alertas rojas empujaba
        // fuera de pantalla las tareas del día, y si todo grita no grita nada. La línea lleva a
        // /tareas filtrado por vencidas, así que resumir no es esconder.
        //
        // Las reuniones se excluyen EXPLÍCITAMENTE, aunque hoy la agenda no traiga ninguna pasada: una
        // reunión no es un pendiente que se arrastre (no puedes ir ya) y la checklist no sabe pintarla,
        // así que si alguien amplía la consulta hacia atrás debe verlo aquí y no en un error de plantilla.
        $overdueTasks = array_values(array_filter($buckets['overdue'], static fn (AgendaEntry $e): bool => AgendaEntry::KIND_TASK === $e->kind));
        // Los RECORDATORIOS personales vencidos NO entran en ese resumen y siguen siendo filas: la línea
        // lleva a /tareas, donde un recordatorio privado no está, así que contarlo ahí sería prometer un
        // sitio al que no se puede ir — perderlo en silencio, justo lo que el resumen quiere evitar.
        // Tampoco hacen muro: te los pones tú y son pocos, mientras que el arrastre de vencidas es del
        // centro. Van delante de lo que viene, que es el orden en que aprietan.
        $overdueReminders = array_values(array_filter(
            $buckets['overdue'],
            static fn (AgendaEntry $e): bool => AgendaEntry::KIND_EVENT === $e->kind && null !== $e->event && $e->event->isAllDay(),
        ));
        // Y lo que queda por delante, en orden de fecha: hoy, la semana y lo lejano. Las tres cestas y no
        // solo la de hoy, porque el bloque se llama "Por hacer" y no "hoy": un día sin nada que venza
        // mostraría el contenedor vacío teniendo trabajo esperando el lunes.
        $ahead = [
            ...$overdueReminders,
            ...array_values(array_filter(
                [...$buckets['today'], ...$buckets['week'], ...$buckets['later']],
                $isTodo,
            )),
        ];

        // ---- "Próximos 7 días" -------------------------------------------------------------------
        // Lo que se atiende a una hora en los días siguientes: guardias, reuniones y citas privadas. Las
        // tareas NO entran aquí aunque venzan esta semana — ya están en "Por hacer", y contarlas dos veces
        // hacía leer dos compromisos donde hay uno. Lo lejano vive en el Calendario.
        $upcoming = [
            ...array_filter($buckets['week'], static fn (AgendaEntry $e): bool => !$isTodo($e)),
            ...$guardias['weekEntries'],
        ];
        usort($upcoming, static fn (AgendaEntry $a, AgendaEntry $b): int => $a->date <=> $b->date);

        return [
            'nextGuardia' => $guardias['next'],
            'guardiasTodayCount' => $guardias['todayCount'],
            'upcomingGuardia' => $guardias['upcomingGuardia'],
            'breakDutiesToday' => $breakDuties,
            'dayTimeline' => $this->dayTimeline($guardias['todayItems'], $breakDuties, $buckets['today'], $now),
            'todos' => \array_slice($ahead, 0, self::TODOS_SHOWN),
            // Todo lo que hay por hacer, no lo que se pinta: es la cifra de la cabecera y del pie, y los
            // dos llevan a la lista donde está entero.
            'todosTotal' => \count($overdueTasks) + \count($ahead),
            'overdueCount' => \count($overdueTasks),
            // La más antigua da la medida del retraso ("desde el 12/11"), que un número solo no da. Las
            // cestas vienen en orden cronológico, así que es la primera.
            'overdueOldest' => $overdueTasks[0]->date ?? null,
            'upcoming' => \array_slice($upcoming, 0, self::UPCOMING_SHOWN),
            // El contador de la cabecera cuenta la SEMANA, no las filas pintadas: leerlo del recorte diría
            // "6 citas" en una semana de nueve, y el contador está justamente para que no haya que
            // preguntarse si la lista está completa.
            'upcomingTotal' => \count($upcoming),
            'roleSubtitle' => $this->roleSubtitle($user),
        ];
    }

    /**
     * "Tu día": todo lo de HOY que tiene hora, en una sola línea temporal — las guardias asignadas, la
     * guardia de recreo que toque ese día de la semana, las reuniones a las que se convoca al usuario y
     * sus citas privadas. Antes eran dos bloques ("Reuniones de hoy" y "Con hora") que se leían por
     * separado y dejaban media pantalla vacía, cuando la pregunta es una sola: ¿qué tengo hoy a una hora?
     *
     * Cada fila lleva su estado respecto al reloj, que es lo que la plantilla necesita para atenuar lo
     * pasado y marcar con un filo lo que viene ahora:
     *  - `past`: ya terminó. Para una guardia lo decide {@see TeacherGuardiaDay} (sabe la hora de fin del
     *    tramo, y sin horario importado no da nada por terminado); para lo demás, su hora de fin si la
     *    tiene y su hora de inicio si no.
     *  - `now`: la PRIMERA que no ha terminado. No es "está ocurriendo": es lo próximo que hay que
     *    atender, que es lo que se busca al entrar. Si la anterior sigue en curso, esa es la marcada.
     *  - `future`: el resto.
     *
     * Las que no tienen hora conocida (una guardia de un centro sin horario importado) entran igual y
     * conservan el orden de tramo: la plantilla enseña el ordinal ("2ª") en lugar del reloj. Sacarlas
     * sería perder guardias reales por no saber a qué hora son.
     *
     * @param list<array{cover: GuardiaCover, done: bool, startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable, minutesUntil: ?int}> $guardiaItems today's covers as {@see TeacherGuardiaDay} reads them
     * @param list<array{duty: BreakDutyAssignment, entry: AgendaEntry, startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable}>      $breakItems   today's recreos on the rota, as {@see breakDutiesOn()} reads them
     * @param AgendaEntry[]                                                                                                              $todayEntries the agenda's "today" bucket (meetings and events are taken from it)
     * @param \DateTimeImmutable                                                                                                         $now          the current instant
     *
     * @return list<array{entry: AgendaEntry, startsAt: ?\DateTimeImmutable, minutesUntil: ?int, state: string}> the day's rows, earliest first
     */
    private function dayTimeline(array $guardiaItems, array $breakItems, array $todayEntries, \DateTimeImmutable $now): array
    {
        $minutesUntil = static fn (?\DateTimeImmutable $startsAt): ?int => null !== $startsAt && $startsAt > $now
            ? intdiv($startsAt->getTimestamp() - $now->getTimestamp(), 60)
            : null;

        $rows = [];
        foreach ($guardiaItems as $item) {
            $rows[] = [
                'entry' => AgendaEntry::fromGuardia($item['cover'], $item['startsAt']),
                'startsAt' => $item['startsAt'],
                'minutesUntil' => $item['minutesUntil'],
                'over' => $item['done'],
            ];
        }
        foreach ($breakItems as $item) {
            // Un recreo termina cuando lo dice el marco horario. Sin horario importado no se sabe la
            // hora, y entonces NO se da por pasado: como con las guardias, no saber cuándo es no es
            // razón para atenuar algo que todavía puede estar por llegar.
            $rows[] = [
                'entry' => $item['entry'],
                'startsAt' => $item['startsAt'],
                'minutesUntil' => $minutesUntil($item['startsAt']),
                'over' => null !== $item['endsAt'] && $item['endsAt'] < $now,
            ];
        }
        foreach ($todayEntries as $entry) {
            $startsAt = match ($entry->kind) {
                AgendaEntry::KIND_MEETING => $entry->meeting?->getStartAt(),
                // Un recordatorio de todo el día no tiene hora: es un "por hacer", y vive en el otro bloque.
                AgendaEntry::KIND_EVENT => null !== $entry->event && !$entry->event->isAllDay() ? $entry->event->getStartAt() : null,
                default => null,
            };
            if (null === $startsAt) {
                continue;
            }
            $endsAt = AgendaEntry::KIND_MEETING === $entry->kind
                ? $entry->meeting?->getEndAt()
                : $entry->event?->getEndAt();
            $rows[] = [
                'entry' => $entry,
                'startsAt' => $startsAt,
                'minutesUntil' => $minutesUntil($startsAt),
                'over' => ($endsAt ?? $startsAt) < $now,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['entry']->date <=> $b['entry']->date);

        $markedNow = false;
        $timeline = [];
        foreach ($rows as $row) {
            $state = match (true) {
                $row['over'] => 'past',
                !$markedNow => 'now',
                default => 'future',
            };
            $markedNow = $markedNow || 'now' === $state;
            $timeline[] = [
                'entry' => $row['entry'],
                'startsAt' => $row['startsAt'],
                'minutesUntil' => $row['minutesUntil'],
                'state' => $state,
            ];
        }

        return $timeline;
    }

    /**
     * Role-aware modules layered on top of the base, one per hat the user wears. A plain teacher gets
     * none (empty array); a head of department gets "department"; direction/leadership gets "centre".
     * The task figures come from a single {@see CentreDashboard::overview()} pass over the year's tasks.
     *
     * @return array{
     *     guardiasToday?: array{total: int, covered: int, uncovered: int},
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
        // Coordinación de guardias: cómo va el parte de HOY. No solo los huecos — un día resuelto también
        // es noticia ("todo cubierto · 12 de 12"), y sin el total el cero no se distingue de "hoy no hay
        // ausencias", que son dos días muy distintos para quien reparte.
        if ($isGuardiaCoordinator) {
            $coverage = $this->covers->coverageOn($today);
            $modules['guardiasToday'] = [
                'total' => $coverage['total'],
                'covered' => $coverage['total'] - $coverage['uncovered'],
                'uncovered' => $coverage['uncovered'],
            ];
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
                fn (Task $t): bool => $this->workflows->isAwaitingVerdict($t),
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
     *  - `todayItems`: ALL of today's, in period order and each flagged done or not, for the "Tu día"
     *    timeline (the ones already over included: a day with three guardias must read as three, and
     *    seeing a finished one greyed out is the difference between "hecha" and "no la tenías");
     *  - `todayCount`: how many there are today at all, so the "hoy no tienes guardia" strip can tell
     *    "no tienes ninguna" apart from "ya las has hecho todas";
     *  - `upcomingGuardia`: the next future one, for that same strip, at ANY distance (a guardia three
     *    weeks out still deserves the mention when today is clear);
     *  - `weekEntries`: the ones in the next 7 days as agenda entries, for "Próximos 7 días".
     *
     * One query feeds all five: the covers assigned from today on, split by day in PHP. Period times
     * come from the slot index via the course's timetable.
     *
     * @param User               $user  the viewer
     * @param AcademicYear|null  $year  the course today belongs to, or null when there is none
     * @param \DateTimeImmutable $today the reference day (midnight)
     * @param \DateTimeImmutable $now   the current instant
     *
     * @return array{
     *     next: array{cover: GuardiaCover, done: bool, startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable, minutesUntil: ?int, inProgress: bool}|null,
     *     todayItems: list<array{cover: GuardiaCover, done: bool, startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable, minutesUntil: ?int}>,
     *     todayCount: int,
     *     upcomingGuardia: array{cover: GuardiaCover, startsAt: ?\DateTimeImmutable}|null,
     *     weekEntries: list<AgendaEntry>
     * }
     */
    private function guardias(User $user, ?AcademicYear $year, \DateTimeImmutable $today, \DateTimeImmutable $now): array
    {
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
        if (null !== $next) {
            // "En curso" y "aún por llegar" no se dicen igual: el hero de una guardia que YA está pasando
            // no puede llamarse "tu próxima guardia". Se deduce de lo que el reloj ya sabe en vez de
            // volver a compararlo: si se conoce la hora de inicio y no queda ninguna cuenta atrás, es que
            // empezó; y si hubiera terminado no sería "next" ({@see TeacherGuardiaDay}). Sin horario
            // importado no hay hora y no se afirma nada.
            $next['inProgress'] = null !== $next['startsAt'] && null === $next['minutesUntil'];
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
            'todayItems' => $day['items'],
            'todayCount' => $day['counts']['assigned'],
            'upcomingGuardia' => (null === $next && [] !== $future)
                ? ['cover' => $future[0], 'startsAt' => $this->startOf($future[0], $slotTimes)]
                : null,
            'weekEntries' => $weekEntries,
        ];
    }

    /**
     * The viewer's break duty rota for ONE day — their "hoy te toca el patio a las 11:10".
     *
     * The rota is a weekly pattern fixed for the whole course ({@see BreakDutyAssignment}), which is why
     * "Mis guardias" states it once ("los martes, patio") instead of listing every Tuesday of the year.
     * Inicio needs the other reading: it answers "qué me toca HOY", and a duty nobody is reminded of on
     * the morning it falls is a zone that quietly goes unwatched. So the pattern is projected onto today
     * here, and nothing is persisted per day.
     *
     * Three things keep it from lying:
     *  - the rota is only shown once ANNOUNCED, the same gate as {@see \App\Controller\GuardiaController::mine()}:
     *    while it is a draft the equipo directivo is still moving places about;
     *  - only on a teaching day ({@see SchoolCalendar}), so a Tuesday that happens to be a holiday does
     *    not claim a recreo;
     *  - and never for a place already recorded as a {@see \App\Entity\BreakDutyGap}: somebody who has
     *    registered that they are away that day would otherwise be told to turn up to the very recreo
     *    their absence released.
     *
     * @param User               $user  the viewer
     * @param AcademicYear|null  $year  the course the day belongs to, or null when there is none
     * @param \DateTimeImmutable $today the day to project the rota onto (midnight)
     *
     * @return list<array{duty: BreakDutyAssignment, entry: AgendaEntry, startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable}> the day's recreos, earliest first
     */
    private function breakDutiesOn(User $user, ?AcademicYear $year, \DateTimeImmutable $today): array
    {
        if (null === $year || !$year->isBreakRotaAnnounced()) {
            return [];
        }

        // El orden de las guardas es el orden de su coste: primero lo que no cuesta consulta, luego las
        // plazas y solo entonces el calendario. Quien no tiene recreo ese día de la semana —la mayor
        // parte del claustro, la mayor parte de los días— no llega a preguntar si el día es lectivo.
        $duties = $this->breakDuties->findAllForTeacherAndWeekday($year, $user, Weekday::from((int) $today->format('N')));
        if ([] === $duties || !$this->calendar->isLective($today)) {
            return [];
        }

        $withGap = $this->breakGaps->findAssignmentIdsWithGapOn($duties, $today);
        // Las horas del recreo salen del marco horario por POSICIÓN (primer recreo, segundo), nunca por
        // índice de tramo: los índices de Peñalara cambian de un curso a otro. Misma resolución que
        // guardia/_break_times.html.twig, que es lo que se lee en las otras tres pantallas.
        $breaks = $this->timeSlots->findBreaksByYear($year);

        $rows = [];
        foreach ($duties as $duty) {
            if (\in_array($duty->getId(), $withGap, true)) {
                continue;
            }
            $slot = $breaks[$duty->getPeriod()->position()] ?? null;
            // Del marco horario sale la HORA (sin fecha: es una columna TIME), y el día lo pone hoy.
            $startsAt = null !== $slot ? $today->setTime((int) $slot->getStartsAt()->format('G'), (int) $slot->getStartsAt()->format('i')) : null;
            $endsAt = null !== $slot ? $today->setTime((int) $slot->getEndsAt()->format('G'), (int) $slot->getEndsAt()->format('i')) : null;
            $rows[] = [
                'duty' => $duty,
                'entry' => AgendaEntry::fromBreakDuty($duty, $today, $startsAt),
                'startsAt' => $startsAt,
                'endsAt' => $endsAt,
            ];
        }

        return $rows;
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
