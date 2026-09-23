<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agenda\ClassSession;
use App\Agenda\DayTimeline;
use App\Agenda\MyClasses;
use App\Agenda\TimelineBlock;
use App\Entity\AcademicYear;
use App\Entity\NonLectiveDay;
use App\Entity\GuardiaCover;
use App\Entity\LessonPlan;
use App\Entity\Meeting;
use App\Entity\PersonalEvent;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\CategoryColor;
use App\Repository\AcademicYearRepository;
use App\Repository\GuardiaCoverRepository;
use App\Repository\LessonPlanRepository;
use App\Repository\MeetingRepository;
use App\Repository\NonLectiveDayRepository;
use App\Repository\PersonalEventRepository;
use App\Repository\TaskRepository;
use App\Repository\TimeSlotRepository;
use App\Service\SchoolCalendar;
use App\Service\TaskVisibility;
use App\Util\AppTime;
use App\Util\CalendarDate;
use App\Util\SchoolYear;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Calendar view of the course task plan, laying each task out by its deadline. The same page serves
 * four zoom levels selected with the "vista" query parameter — day (agenda), week, month and year —
 * all anchored on the "fecha" (YYYY-MM-DD) parameter, with previous/next navigation per level.
 *
 * @phpstan-type DayCell array{date: \DateTimeImmutable, inMonth: bool, isToday: bool, isWeekend: bool, nonLective: ?NonLectiveDay, tasks: Task[], events: PersonalEvent[]}
 * @phpstan-type MiniCell array{day: string, date: string, inMonth: bool, isToday: bool, hasTasks: bool, hasEvents: bool, hasGuardias: bool, status: ?string, isNonLective: bool}
 */
final class CalendarController extends AbstractController
{
    /** The day, week, month and year views, and the one used when "vista" is missing or unknown. */
    private const array VIEWS = ['dia', 'semana', 'mes', 'anio'];
    private const string DEFAULT_VIEW = 'mes';

    /** Spanish month names, indexed 1–12, for the calendar labels. */
    private const array MONTH_NAMES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /** Spanish weekday names, indexed by ISO-8601 day of week (1 = Monday … 7 = Sunday). */
    private const array WEEKDAY_NAMES = [
        1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo',
    ];

    /**
     * Task status priority for the single dot shown per day in the year view, most attention-needing
     * first: pendiente (por hacer), en revisión (devuelta, hay que corregirla), entregada (a validar),
     * finalizada y por último cancelada. Un único ciclo de vida (ver config/packages/workflow.yaml).
     *
     * En revisión va por delante de Entregada porque es trabajo de quien mira el calendario, mientras que
     * lo entregado está en manos de otra persona.
     */
    private const array STATUS_PRIORITY = ['pending', 'in_review', 'submitted', 'validated', 'cancelled'];

    /** Fixed timeline colours for guardias and meetings: unlike a class, there is no "identity" worth
     *  telling apart block from block, so every one of a kind gets the same colour. */
    private const string GUARDIA_COLOR = 'cat-color--amber';
    private const string MEETING_COLOR = 'cat-color--slate';

    /**
     * Renders the calendar at the requested zoom level and anchor date (both optional), with the
     * tasks whose deadline falls on each visible day and the non-teaching days marked.
     *
     * @param Request                 $request        the HTTP request; optional query params "vista" and "fecha"
     * @param User                    $user           the authenticated user, to scope the visible tasks
     * @param TaskRepository          $tasks          the task repository
     * @param TaskVisibility          $visibility     the task visibility scope built from the organisation chart
     * @param NonLectiveDayRepository $nonLectiveDays the non-teaching day repository
     * @param SchoolCalendar          $schoolCalendar the teaching-day calendar, to flag weekends
     *
     * @return Response the rendered calendar page
     */
    #[Route('/calendario', name: 'calendar_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, TaskRepository $tasks, TaskVisibility $visibility, NonLectiveDayRepository $nonLectiveDays, SchoolCalendar $schoolCalendar, AcademicYearRepository $academicYears, PersonalEventRepository $personalEvents, GuardiaCoverRepository $covers, MeetingRepository $meetings, MyClasses $myClasses, LessonPlanRepository $lessonPlans, TimeSlotRepository $timeSlots, DayTimeline $timeline): Response
    {
        // Explicit zone because the grid parses a "YYYY-MM-DD" from the query string into a midnight;
        // it is the SAME zone PHP now defaults to ({@see \App\Kernel}), so this no longer decides
        // anything the rest of the app might decide differently — it only says it out loud.
        $timeZone = AppTime::zone();
        $today = new \DateTimeImmutable('today', $timeZone);
        $view = $this->resolveView($request->query->getString('vista'));
        $anchor = $this->resolveDate($request->query->getString('fecha'), $today, $timeZone);

        [$rangeStart, $rangeEnd] = $this->rangeFor($view, $anchor);
        $visible = $visibility->visibleTo($tasks->findDueBetween($rangeStart, $rangeEnd), $user, $this->isGranted('ROLE_ADMIN'));
        $byDay = $this->groupByDay($visible, static fn (Task $task): string => $task->getDueDate()->format('Y-m-d'));
        // The user's own private events in the same window (scoped by owner in the repository). The
        // range end is a day at midnight, so widen it to the end of that day to catch events with a time.
        $eventsByDay = $this->groupByDay(
            $personalEvents->findForOwnerBetween($user, $rangeStart, $rangeEnd->setTime(23, 59, 59)),
            static fn (PersonalEvent $event): string => $event->getStartAt()->format('Y-m-d'),
        );
        // The user's own guardias in the same window, laid out by day next to tasks and events.
        $guardiasByDay = $this->groupByDay(
            $covers->findAssignedToBetween($user, $rangeStart, $rangeEnd),
            static fn (GuardiaCover $g): string => $g->getDate()->format('Y-m-d'),
        );
        // Las reuniones a las que el usuario está convocado, en la misma ventana. El rango acaba en un día
        // a medianoche, así que se ensancha al final de ese día para no perder las de la tarde.
        $meetingsByDay = $this->groupByDay(
            $meetings->findForUserBetween($user, $rangeStart, $rangeEnd->setTime(23, 59, 59)),
            static fn (Meeting $m): string => $m->getStartAt()->format('Y-m-d'),
        );
        $nonLectiveByDay = $this->indexNonLectiveDays($nonLectiveDays->findBetween($rangeStart, $rangeEnd));

        // Las clases del propio docente (Y-m-d → ClassSession[]), solo en día y semana: en el mes serían
        // treinta por celda y taparían lo demás, y en el año no caben. Quien no da clase no tiene ninguna.
        // Calculadas ya aquí (no dentro del render, como antes) porque la rejilla horaria las necesita
        // para colocar sus bloques.
        $classesByDay = \in_array($view, ['dia', 'semana'], true) ? $myClasses->between($user, $rangeStart, $rangeEnd) : [];
        $plansByClass = \in_array($view, ['dia', 'semana'], true) ? $lessonPlans->findForTeacherBetween($user, $rangeStart, $rangeEnd) : [];
        // El marco horario del curso de ese rango, para dar hora real a las guardias (que solo guardan su
        // tramo): mismo origen que MyClasses usa para las clases, así los bloques de una y otra cuadran.
        $frame = \in_array($view, ['dia', 'semana'], true)
            ? $timeSlots->lectiveTimesWithFallback($academicYears->findBySchoolYear(SchoolYear::current($anchor)))['slots']
            : [];

        $model = match ($view) {
            'dia' => $this->dayModel($anchor, $today, $byDay, $eventsByDay, $guardiasByDay, $meetingsByDay, $nonLectiveByDay, $schoolCalendar, $classesByDay, $plansByClass, $frame, $timeline),
            'semana' => $this->weekModel($anchor, $today, $byDay, $eventsByDay, $guardiasByDay, $meetingsByDay, $nonLectiveByDay, $schoolCalendar, $classesByDay, $plansByClass, $frame, $timeline),
            'anio' => $this->yearModel($anchor, $today, $byDay, $eventsByDay, $guardiasByDay, $nonLectiveByDay, $schoolCalendar, $academicYears),
            default => $this->monthModel($anchor, $today, $byDay, $eventsByDay, $nonLectiveByDay, $schoolCalendar),
        };

        return $this->render('calendar/index.html.twig', [
            'view' => $view,
            'anchor' => $anchor,
            'views' => self::VIEWS,
            'prevDate' => $this->step($view, $anchor, -1),
            'nextDate' => $this->step($view, $anchor, 1),
            'todayDate' => $today->format('Y-m-d'),
            // Guardias por día (Y-m-d → GuardiaCover[]); las vistas de rejilla las miran por fecha de celda.
            'guardiasByDay' => $guardiasByDay,
            'classesByDay' => $classesByDay,
            // Lo ya programado de esas clases ("Y-m-d|tramo" → plan), en una consulta: solo del propio docente.
            'plansByClass' => $plansByClass,
            ...$model,
        ]);
    }

    /**
     * Normalises the "vista" parameter to one of the known views, falling back to the month view.
     *
     * @param string $raw the raw "vista" value
     *
     * @return string one of {@see self::VIEWS}
     */
    private function resolveView(string $raw): string
    {
        return \in_array($raw, self::VIEWS, true) ? $raw : self::DEFAULT_VIEW;
    }

    /**
     * Parses the "fecha" parameter into a day, falling back to today when it is missing or malformed.
     *
     * @param string             $raw      the raw "fecha" value, expected in "YYYY-MM-DD" form
     * @param \DateTimeImmutable $today    the reference "today" used for the fallback
     * @param \DateTimeZone      $timeZone the application time zone
     *
     * @return \DateTimeImmutable midnight on the resolved day
     */
    private function resolveDate(string $raw, \DateTimeImmutable $today, \DateTimeZone $timeZone): \DateTimeImmutable
    {
        return CalendarDate::parse($raw, $timeZone) ?? $today;
    }

    /**
     * The inclusive day range whose tasks the given view needs: the day itself, its Monday–Sunday
     * week, the full visible month grid (including spill-over days), or the whole calendar year.
     *
     * @param string             $view   the resolved view
     * @param \DateTimeImmutable $anchor the anchor day
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} the range start and end
     */
    private function rangeFor(string $view, \DateTimeImmutable $anchor): array
    {
        if ('semana' === $view) {
            $start = $this->weekStart($anchor);

            return [$start, $start->modify('+6 days')];
        }

        $year = (int) $anchor->format('Y');

        if ('anio' === $view) {
            // The year view shows the SCHOOL year (September→August), not the calendar year, so its
            // three terms read left to right.
            $start = $this->schoolYearStart($anchor);

            return [$start, $start->modify('+1 year')->modify('-1 day')];
        }

        return match ($view) {
            'dia' => [$anchor, $anchor],
            default => $this->monthGridRange($anchor),
        };
    }

    /**
     * The first day (1 September) of the school year the anchor falls in. From September on the anchor
     * is in the year that starts that calendar year; before September, in the one that started the
     * previous year.
     *
     * @param \DateTimeImmutable $anchor the anchor day
     *
     * @return \DateTimeImmutable 1 September of the school year's starting calendar year
     */
    private function schoolYearStart(\DateTimeImmutable $anchor): \DateTimeImmutable
    {
        $year = (int) $anchor->format('Y');
        $startYear = (int) $anchor->format('n') >= 9 ? $year : $year - 1;

        return $anchor->setDate($startYear, 9, 1);
    }

    /**
     * The anchor date shifted one step in the given direction at the granularity of the view: a day,
     * a week, a month or a year. Used to build the previous/next navigation links.
     *
     * @param string             $view   the resolved view
     * @param \DateTimeImmutable $anchor the current anchor day
     * @param int                $dir    the direction, -1 for previous or +1 for next
     *
     * @return string the shifted day in "YYYY-MM-DD" form
     */
    private function step(string $view, \DateTimeImmutable $anchor, int $dir): string
    {
        $shifted = match ($view) {
            'dia' => $anchor->modify(\sprintf('%+d days', $dir)),
            'semana' => $anchor->modify(\sprintf('%+d days', 7 * $dir)),
            // Keep the same month/day so switching Year→Month after the jump lands on the right month;
            // setDate normalises a 29-Feb anchor into a non-leap target year natively.
            'anio' => $anchor->setDate((int) $anchor->format('Y') + $dir, (int) $anchor->format('n'), (int) $anchor->format('j')),
            default => $anchor->modify('first day of this month')->modify(\sprintf('%+d month', $dir)),
        };

        return $shifted->format('Y-m-d');
    }

    /**
     * Groups the given items by day (keyed "YYYY-MM-DD"), where each item's day is read by $dayOf.
     * Shared by tasks (by deadline) and personal events (by start), so the two never drift apart.
     *
     * @template T
     *
     * @param T[]                 $items the items to group
     * @param callable(T): string $dayOf reads an item's "YYYY-MM-DD" day
     *
     * @return array<string, T[]> the items indexed by day
     */
    private function groupByDay(array $items, callable $dayOf): array
    {
        $byDay = [];
        foreach ($items as $item) {
            $byDay[$dayOf($item)][] = $item;
        }

        return $byDay;
    }

    /**
     * Indexes the given non-teaching days by day, keyed "YYYY-MM-DD".
     *
     * @param NonLectiveDay[] $days the non-teaching days to index
     *
     * @return array<string, NonLectiveDay> the non-teaching days indexed by day
     */
    private function indexNonLectiveDays(array $days): array
    {
        $byDay = [];
        foreach ($days as $day) {
            $byDay[$day->getDate()->format('Y-m-d')] = $day;
        }

        return $byDay;
    }

    /**
     * The day-view model: the single anchor day as an hourly timeline, its classes, guardias and meetings
     * drawn as blocks sized to their real duration ({@see DayTimeline}), and everything else (tasks,
     * personal events, a class with no marco horario to time it) left in a plain list.
     *
     * @param \DateTimeImmutable                                                         $anchor          the anchor day
     * @param \DateTimeImmutable                                                         $today           today, to flag the current day
     * @param array<string, Task[]>                                                      $byDay           tasks indexed by deadline day
     * @param array<string, PersonalEvent[]>                                             $eventsByDay     personal events indexed by start day
     * @param array<string, GuardiaCover[]>                                              $guardiasByDay   the user's guardias indexed by day
     * @param array<string, Meeting[]>                                                   $meetingsByDay   the meetings the user is convened to, indexed by day
     * @param array<string, NonLectiveDay>                                               $nonLectiveByDay non-teaching days indexed by day
     * @param SchoolCalendar                                                             $schoolCalendar  the teaching-day calendar
     * @param array<string, list<ClassSession>>                                          $classesByDay    the teacher's classes indexed by day
     * @param array<string, LessonPlan>                                      $plansByClass    "Y-m-d|tramo" → plan
     * @param array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> $frame           the course's periods, index → clock times
     * @param DayTimeline                                                                $timeline        the grid layout engine
     *
     * @return array{template: string, label: string, day: array<string, mixed>} the template and view data
     */
    private function dayModel(\DateTimeImmutable $anchor, \DateTimeImmutable $today, array $byDay, array $eventsByDay, array $guardiasByDay, array $meetingsByDay, array $nonLectiveByDay, SchoolCalendar $schoolCalendar, array $classesByDay, array $plansByClass, array $frame, DayTimeline $timeline): array
    {
        $cell = $this->cell($anchor, null, $today, $byDay, $eventsByDay, $nonLectiveByDay, $schoolCalendar);
        $key = $anchor->format('Y-m-d');
        $classes = $classesByDay[$key] ?? [];
        $cell['guardias'] = $guardiasByDay[$key] ?? [];
        $cell['meetings'] = $meetingsByDay[$key] ?? [];
        $cell['unscheduledClasses'] = array_values(array_filter($classes, static fn (ClassSession $c): bool => null === $c->startsAt));

        // Los eventos personales se quedan en la lista, no en la rejilla: es donde vive su toque de
        // "hecho" (agenda-check en taskUi.event_item), y un bloque de la rejilla no tiene sitio para un
        // <form> dentro de un enlace.
        $blocks = $this->timedBlocks($anchor, $classes, $cell['guardias'], $cell['meetings'], $frame, $plansByClass);
        [$windowStart, $windowEnd] = $timeline->windowFor($blocks, $anchor);
        $cell['hourMarks'] = $timeline->hourMarks($windowStart, $windowEnd);
        $cell['timeline'] = $timeline->layout($blocks, $windowStart, $windowEnd);
        $cell['nowTop'] = $cell['isToday'] ? $timeline->percentInWindow(new \DateTimeImmutable('now', AppTime::zone()), $windowStart, $windowEnd) : null;

        return [
            'template' => 'calendar/_day.html.twig',
            'label' => self::WEEKDAY_NAMES[(int) $anchor->format('N')].', '.$anchor->format('j').' de '.$this->monthName($anchor).' de '.$anchor->format('Y'),
            'day' => $cell,
        ];
    }

    /**
     * The week-view model: the Monday–Sunday week containing the anchor day, as seven timelines sharing
     * ONE hour axis (so a 10:00 row lines up from Monday's column to Friday's) — the same per-day layout
     * as {@see dayModel()}, windowed once across the whole week instead of once per day.
     *
     * @param \DateTimeImmutable                                                         $anchor          the anchor day
     * @param \DateTimeImmutable                                                         $today           today, to flag the current day
     * @param array<string, Task[]>                                                      $byDay           tasks indexed by deadline day
     * @param array<string, PersonalEvent[]>                                             $eventsByDay     personal events indexed by start day
     * @param array<string, GuardiaCover[]>                                              $guardiasByDay   the user's guardias indexed by day
     * @param array<string, Meeting[]>                                                   $meetingsByDay   the meetings the user is convened to, indexed by day
     * @param array<string, NonLectiveDay>                                               $nonLectiveByDay non-teaching days indexed by day
     * @param SchoolCalendar                                                             $schoolCalendar  the teaching-day calendar
     * @param array<string, list<ClassSession>>                                          $classesByDay    the teacher's classes indexed by day
     * @param array<string, LessonPlan>                                      $plansByClass    "Y-m-d|tramo" → plan
     * @param array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> $frame           the course's periods, index → clock times
     * @param DayTimeline                                                                $timeline        the grid layout engine
     *
     * @return array{template: string, label: string, week: list<array<string, mixed>>, hourMarks: list<array{label: string, top: float}>} the template and view data
     */
    private function weekModel(\DateTimeImmutable $anchor, \DateTimeImmutable $today, array $byDay, array $eventsByDay, array $guardiasByDay, array $meetingsByDay, array $nonLectiveByDay, SchoolCalendar $schoolCalendar, array $classesByDay, array $plansByClass, array $frame, DayTimeline $timeline): array
    {
        $start = $this->weekStart($anchor);
        $end = $start->modify('+6 days');

        $week = [];
        $blocksByDay = [];
        for ($day = 0; $day < 7; ++$day) {
            $date = $start->modify('+'.$day.' days');
            $key = $date->format('Y-m-d');
            $cell = $this->cell($date, null, $today, $byDay, $eventsByDay, $nonLectiveByDay, $schoolCalendar);
            $classes = $classesByDay[$key] ?? [];
            $cell['guardias'] = $guardiasByDay[$key] ?? [];
            $cell['meetings'] = $meetingsByDay[$key] ?? [];
            $cell['unscheduledClasses'] = array_values(array_filter($classes, static fn (ClassSession $c): bool => null === $c->startsAt));

            $blocksByDay[$key] = $this->timedBlocks($date, $classes, $cell['guardias'], $cell['meetings'], $frame, $plansByClass);
            $week[] = $cell;
        }

        // Una sola ventana para toda la semana, calculada sobre TODOS los bloques a la vez: si el lunes
        // pide 8-15h y el jueves 8-18h, los siete días muestran 8-18h, no cada uno la suya (si no, las
        // filas de hora no alinearían de una columna a otra).
        [$refStart, $refEnd] = $timeline->windowFor(array_merge(...array_values($blocksByDay)), $start);
        $now = new \DateTimeImmutable('now', AppTime::zone());
        foreach ($week as &$cell) {
            $date = $cell['date'];
            $dayStart = $date->setTime((int) $refStart->format('H'), (int) $refStart->format('i'));
            $dayEnd = $date->setTime((int) $refEnd->format('H'), (int) $refEnd->format('i'));
            $cell['timeline'] = $timeline->layout($blocksByDay[$date->format('Y-m-d')], $dayStart, $dayEnd);
            $cell['nowTop'] = $cell['isToday'] ? $timeline->percentInWindow($now, $dayStart, $dayEnd) : null;
        }
        unset($cell);

        return [
            'template' => 'calendar/_week.html.twig',
            'label' => $this->rangeLabel($start, $end),
            'week' => $week,
            'hourMarks' => $timeline->hourMarks($refStart, $refEnd),
        ];
    }

    /**
     * Builds every timed block a day's timeline can show: classes with a resolved time, guardias (timed
     * via the course's own periods — they only store which one, not its clock time) and meetings.
     * Personal events are NOT here on purpose ({@see dayModel()}): their one-tap "done" toggle is a
     * `<form>`, which cannot nest inside a block's `<a>`, so they stay in the plain list, timed or not,
     * same as a task. Anything else that cannot be timed (no marco horario) is the caller's job to keep
     * in that list too — this only ever returns what belongs on the grid.
     *
     * @param \DateTimeImmutable                                                         $day          the day the blocks belong to
     * @param list<ClassSession>                                                         $classes      the day's classes
     * @param list<GuardiaCover>                                                         $guardias     the day's guardias
     * @param list<Meeting>                                                              $meetings     the day's meetings
     * @param array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> $frame        the course's periods, index → clock times
     * @param array<string, LessonPlan>                                      $plansByClass "Y-m-d|tramo" → plan
     *
     * @return list<TimelineBlock> the blocks
     */
    private function timedBlocks(\DateTimeImmutable $day, array $classes, array $guardias, array $meetings, array $frame, array $plansByClass): array
    {
        $dayKey = $day->format('Y-m-d');
        $blocks = [];

        foreach ($classes as $class) {
            if (null === $class->startsAt || null === $class->endsAt) {
                continue; // Sin marco horario que lo fecha: se queda en la lista de "sin horario" del día.
            }
            $groups = $class->groups();
            $blocks[] = new TimelineBlock(
                'class',
                $class->startsAt,
                $class->endsAt,
                [] !== $groups ? implode(', ', $groups) : $class->ordinal.'ª hora',
                $this->classSubtitle($class, $plansByClass[$dayKey.'|'.$class->slotIndex] ?? null),
                $this->generateUrl('lesson_plan', ['fecha' => $dayKey, 'tramo' => $class->slotIndex]),
                $this->colorClassFor(implode(',', $groups)),
                $class->isRelocated(),
            );
        }

        foreach ($guardias as $guardia) {
            $times = $frame[$guardia->getSlotIndex()] ?? null;
            if (null === $times) {
                continue; // El tramo no está en el marco horario del curso: no se puede fechar (raro).
            }
            $blocks[] = new TimelineBlock(
                'guardia',
                CalendarDate::at($day, $times['startsAt']),
                CalendarDate::at($day, $times['endsAt']),
                'Guardia'.(null !== $guardia->effectiveRoomName() ? ' · '.$guardia->effectiveRoomName() : ''),
                'Cubres a '.$guardia->getAbsentTeacher()->getFullName().(null !== $guardia->getGroupName() ? ' · '.$guardia->getGroupName() : ''),
                $this->generateUrl('guardia_cover_show', ['id' => $guardia->getId()]),
                self::GUARDIA_COLOR,
                $guardia->isNotCovered(),
            );
        }

        foreach ($meetings as $meeting) {
            $startsAt = $meeting->getStartAt();
            $blocks[] = new TimelineBlock(
                'meeting',
                $startsAt,
                $this->endWithinDay($startsAt, $meeting->getEndAt() ?? $startsAt->modify('+60 minutes')),
                $meeting->getTitle(),
                $meeting->getPlace(),
                $this->generateUrl('meeting_show', ['id' => $meeting->getId()]),
                self::MEETING_COLOR,
            );
        }

        return $blocks;
    }

    /**
     * Keeps a block's end on the SAME day as its start: a meeting or event with no explicit end gets a
     * synthetic one (+60/+30 minutes), and a start close enough to midnight would otherwise wrap it to
     * 00:00 — reading as "ends before it starts" to {@see DayTimeline}, which floors an end before its
     * start to a sliver at the TOP of the day instead of the late block it actually is.
     */
    private function endWithinDay(\DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): \DateTimeImmutable
    {
        $dayEnd = $startsAt->setTime(23, 59, 59);

        return min($endsAt, $dayEnd);
    }

    /**
     * A class block's subtitle: its subject and real room, and what its plan says (or "sin programar"
     * when there is none) — the same chain a plan's topics read as in {@see \App\Controller\LessonPlanController}.
     */
    private function classSubtitle(ClassSession $class, ?LessonPlan $plan): string
    {
        $parts = [];
        if ([] !== $class->subjects()) {
            $parts[] = implode(', ', $class->subjects());
        }
        if ([] !== $class->rooms()) {
            $parts[] = implode(', ', $class->rooms());
        }

        if (null === $plan) {
            $parts[] = 'sin programar';

            return implode(' · ', $parts);
        }

        $entries = [];
        foreach ($plan->getTopics() as $entry) {
            $bits = array_filter([$entry->getTopic()?->getName(), $entry->getActivity()?->label(), $entry->getOutcome()?->label()]);
            if ([] !== $bits) {
                $entries[] = implode(' · ', $bits);
            }
        }
        $parts[] = [] !== $entries ? implode(' → ', $entries) : 'programada';

        return implode(' · ', $parts);
    }

    /**
     * A stable colour for a group (or any other identity a block wants coloured by): the same key always
     * lands on the same colour of the fixed palette, so a group reads as "the same block colour" from one
     * day to the next without storing anything.
     */
    private function colorClassFor(string $key): string
    {
        $cases = CategoryColor::cases();

        return $cases[abs(crc32($key)) % \count($cases)]->cssClass();
    }

    /**
     * The month-view model: the visible month grid as one row of seven cells per week.
     *
     * @param \DateTimeImmutable                 $anchor          the anchor day (its month is displayed)
     * @param \DateTimeImmutable                 $today           today, to flag the current day
     * @param array<string, Task[]>              $byDay           tasks indexed by deadline day
     * @param array<string, PersonalEvent[]>     $eventsByDay     personal events indexed by start day
     * @param array<string, NonLectiveDay>       $nonLectiveByDay non-teaching days indexed by day
     * @param SchoolCalendar                     $schoolCalendar  the teaching-day calendar
     *
     * @return array{template: string, label: string, weeks: list<list<DayCell>>} the template and view data
     */
    private function monthModel(\DateTimeImmutable $anchor, \DateTimeImmutable $today, array $byDay, array $eventsByDay, array $nonLectiveByDay, SchoolCalendar $schoolCalendar): array
    {
        return [
            'template' => 'calendar/_month.html.twig',
            'label' => $this->monthName($anchor).' '.$anchor->format('Y'),
            'weeks' => $this->monthWeeks($anchor, $today, $byDay, $eventsByDay, $nonLectiveByDay, $schoolCalendar),
        ];
    }

    /**
     * The year-view model: the twelve months of the SCHOOL year (September→August), each as a compact
     * grid whose days carry a single status dot when a task is due and a muted style when non-teaching.
     * Each month is tagged with the term (1–3) it belongs to, when the school year's structure is
     * defined, so the view can colour the three terms.
     *
     * @param \DateTimeImmutable                 $anchor          the anchor day (its school year is displayed)
     * @param \DateTimeImmutable                 $today           today, to flag the current day
     * @param array<string, Task[]>              $byDay           tasks indexed by deadline day
     * @param array<string, PersonalEvent[]>     $eventsByDay     personal events indexed by start day
     * @param array<string, GuardiaCover[]>      $guardiasByDay   the user's guardias indexed by day
     * @param array<string, NonLectiveDay>       $nonLectiveByDay non-teaching days indexed by day
     * @param SchoolCalendar                     $schoolCalendar  the teaching-day calendar
     * @param AcademicYearRepository             $academicYears   the school-year structure repository
     *
     * @return array{template: string, label: string, months: list<array{name: string, date: string, term: ?int, weeks: list<list<MiniCell>>}>} the template and view data
     */
    private function yearModel(\DateTimeImmutable $anchor, \DateTimeImmutable $today, array $byDay, array $eventsByDay, array $guardiasByDay, array $nonLectiveByDay, SchoolCalendar $schoolCalendar, AcademicYearRepository $academicYears): array
    {
        $start = $this->schoolYearStart($anchor);
        $startYear = (int) $start->format('Y');
        $schoolYear = \sprintf('%d-%d', $startYear, $startYear + 1);
        $structure = $academicYears->findBySchoolYear($schoolYear);

        $months = [];
        for ($i = 0; $i < 12; ++$i) {
            $first = $start->modify(\sprintf('+%d months', $i));
            $weeks = [];
            foreach ($this->monthWeeks($first, $today, $byDay, $eventsByDay, $nonLectiveByDay, $schoolCalendar) as $week) {
                $weeks[] = array_map(
                    fn (array $cell): array => $this->miniCell($cell, $guardiasByDay),
                    $week,
                );
            }
            $months[] = [
                'name' => self::MONTH_NAMES[(int) $first->format('n')],
                'date' => $first->format('Y-m-d'),
                'term' => null !== $structure ? $this->termForMonth($structure, $first) : null,
                'weeks' => $weeks,
            ];
        }

        return [
            'template' => 'calendar/_year.html.twig',
            'label' => $schoolYear,
            'months' => $months,
        ];
    }

    /**
     * The term (1–3) a month belongs to, judged by its middle day, or null when that falls in a break
     * or the summer (outside every term).
     *
     * @param AcademicYear       $structure the school year's term structure
     * @param \DateTimeImmutable $first     the first day of the month
     *
     * @return int|null the term number, or null if the month sits outside the terms
     */
    private function termForMonth(AcademicYear $structure, \DateTimeImmutable $first): ?int
    {
        $mid = $first->modify('+14 days');
        foreach ([1, 2, 3] as $term) {
            if ($mid >= $structure->getTermStart($term) && $mid <= $structure->getTermEnd($term)) {
                return $term;
            }
        }

        return null;
    }

    /**
     * Builds the weeks of the visible grid for the month of the given day: from the Monday of its
     * first week to the Sunday of its last, seven cells per week.
     *
     * @param \DateTimeImmutable                 $anchor          the day whose month is laid out
     * @param \DateTimeImmutable                 $today           today, to flag the current day
     * @param array<string, Task[]>              $byDay           tasks indexed by deadline day
     * @param array<string, PersonalEvent[]>     $eventsByDay     personal events indexed by start day
     * @param array<string, NonLectiveDay>       $nonLectiveByDay non-teaching days indexed by day
     * @param SchoolCalendar                     $schoolCalendar  the teaching-day calendar
     *
     * @return list<list<DayCell>> the weeks, each a list of seven day cells
     */
    private function monthWeeks(\DateTimeImmutable $anchor, \DateTimeImmutable $today, array $byDay, array $eventsByDay, array $nonLectiveByDay, SchoolCalendar $schoolCalendar): array
    {
        [$gridStart, $gridEnd] = $this->monthGridRange($anchor);
        $month = $anchor->format('Y-m');

        $weeks = [];
        $cursor = $gridStart;
        while ($cursor <= $gridEnd) {
            $week = [];
            for ($day = 0; $day < 7; ++$day) {
                $week[] = $this->cell($cursor, $month, $today, $byDay, $eventsByDay, $nonLectiveByDay, $schoolCalendar);
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
        }

        return $weeks;
    }

    /**
     * The inclusive range of the visible month grid: the Monday of the month's first week to the
     * Sunday of its last week.
     *
     * @param \DateTimeImmutable $anchor the day whose month grid is measured
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} the grid start and end
     */
    private function monthGridRange(\DateTimeImmutable $anchor): array
    {
        $monthStart = $anchor->modify('first day of this month');
        $monthEnd = $anchor->modify('last day of this month');

        return [
            $monthStart->modify('-'.((int) $monthStart->format('N') - 1).' days'),
            $monthEnd->modify('+'.(7 - (int) $monthEnd->format('N')).' days'),
        ];
    }

    /**
     * The Monday of the ISO week containing the given day.
     *
     * @param \DateTimeImmutable $date the day
     *
     * @return \DateTimeImmutable the Monday of that week
     */
    private function weekStart(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->modify('-'.((int) $date->format('N') - 1).' days');
    }

    /**
     * Builds one day cell. A cell is "in month" when it belongs to $month (or always, for the day and
     * week views where $month is null); only in-month days carry the non-teaching marker, so the
     * month's own non-teaching days stand apart from the neighbouring-month days that spill into the grid.
     *
     * @param \DateTimeImmutable                 $date            the cell's day
     * @param string|null                        $month           the displayed month "YYYY-MM", or null to treat every day as in-month
     * @param \DateTimeImmutable                 $today           today, to flag the current day
     * @param array<string, Task[]>              $byDay           tasks indexed by deadline day
     * @param array<string, PersonalEvent[]>     $eventsByDay     personal events indexed by start day
     * @param array<string, NonLectiveDay>       $nonLectiveByDay non-teaching days indexed by day
     * @param SchoolCalendar                     $schoolCalendar  the teaching-day calendar
     *
     * @return DayCell the cell
     */
    private function cell(\DateTimeImmutable $date, ?string $month, \DateTimeImmutable $today, array $byDay, array $eventsByDay, array $nonLectiveByDay, SchoolCalendar $schoolCalendar): array
    {
        $key = $date->format('Y-m-d');
        $inMonth = null === $month || $date->format('Y-m') === $month;

        return [
            'date' => $date,
            'inMonth' => $inMonth,
            'isToday' => $key === $today->format('Y-m-d'),
            'isWeekend' => $schoolCalendar->isWeekend($date),
            // Neighbouring-month days that spill into the grid are context only: don't mark them non-teaching.
            'nonLective' => $inMonth ? ($nonLectiveByDay[$key] ?? null) : null,
            'tasks' => $byDay[$key] ?? [],
            'events' => $eventsByDay[$key] ?? [],
        ];
    }

    /**
     * Reduces a full day cell to the compact shape the year view needs: the day number, whether it is
     * in its month, today, and the single representative status dot for the tasks due that day.
     *
     * Guardias travel as a plain flag, not as a dot: the mini cell has room for ONE dot and it belongs
     * to the task status, so the year view marks a guardia day in a different visual channel (a rule
     * under the number, see .cal-mini__day.has-guardia) instead of fighting over the dot — which is why
     * they were left out of this view in the first place.
     *
     * @param DayCell                       $cell          the full day cell built by {@see self::cell()}
     * @param array<string, GuardiaCover[]> $guardiasByDay the user's guardias indexed by day
     *
     * @return MiniCell the compact cell
     */
    private function miniCell(array $cell, array $guardiasByDay): array
    {
        $key = $cell['date']->format('Y-m-d');

        return [
            'day' => $cell['date']->format('j'),
            'date' => $key,
            'inMonth' => $cell['inMonth'],
            'isToday' => $cell['isToday'],
            'hasTasks' => $cell['inMonth'] && [] !== $cell['tasks'],
            'hasEvents' => $cell['inMonth'] && [] !== $cell['events'],
            'hasGuardias' => $cell['inMonth'] && [] !== ($guardiasByDay[$key] ?? []),
            'status' => $cell['inMonth'] ? $this->topStatus($cell['tasks']) : null,
            // Non-teaching: a weekend or a registered holiday. Both are shown muted in the year grid.
            'isNonLective' => $cell['inMonth'] && ($cell['isWeekend'] || null !== $cell['nonLective']),
        ];
    }

    /**
     * The most attention-needing status among the given tasks, per {@see self::STATUS_PRIORITY}, or
     * null when there are none. Drives the colour of the single dot shown per day in the year view.
     *
     * @param Task[] $tasks the tasks due on a day
     *
     * @return string|null the representative status, or null when the day has no tasks
     */
    private function topStatus(array $tasks): ?string
    {
        $best = null;
        $bestRank = \PHP_INT_MAX;
        foreach ($tasks as $task) {
            $rank = array_search($task->getStatus(), self::STATUS_PRIORITY, true);
            if (false !== $rank && $rank < $bestRank) {
                $bestRank = $rank;
                $best = $task->getStatus();
            }
        }

        return $best;
    }

    /**
     * The Spanish name of the given day's month.
     *
     * @param \DateTimeImmutable $date the day
     *
     * @return string the month name
     */
    private function monthName(\DateTimeImmutable $date): string
    {
        return self::MONTH_NAMES[(int) $date->format('n')];
    }

    /**
     * A human label for a day range, collapsing the shared month when both ends share it: e.g.
     * "6 – 12 de julio de 2026", or "29 de junio – 5 de julio de 2026" when the week straddles two months.
     *
     * @param \DateTimeImmutable $start the first day of the range
     * @param \DateTimeImmutable $end   the last day of the range
     *
     * @return string the range label
     */
    private function rangeLabel(\DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        $sameYear = $start->format('Y') === $end->format('Y');
        $left = match (true) {
            $sameYear && $start->format('m') === $end->format('m') => $start->format('j'),
            $sameYear => $start->format('j').' de '.$this->monthName($start),
            default => $start->format('j').' de '.$this->monthName($start).' de '.$start->format('Y'),
        };

        return $left.' – '.$end->format('j').' de '.$this->monthName($end).' de '.$end->format('Y');
    }
}
