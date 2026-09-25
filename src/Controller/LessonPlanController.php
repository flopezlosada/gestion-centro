<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agenda\ClassSession;
use App\Agenda\LessonShift;
use App\Agenda\MyClasses;
use App\Entity\LessonPlan;
use App\Entity\Topic;
use App\Entity\User;
use App\Enum\EducationLevel;
use App\Enum\LessonActivity;
use App\Enum\LessonOutcome;
use App\Repository\LessonPlanRepository;
use App\Service\TopicCatalog;
use App\Util\AppTime;
use App\Util\CalendarDate;
use App\Util\GroupCode;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Planning one of your own classes: the topic, what the class is about and how it went — with taps, not
 * typing, because it is filled on a phone between classes. A free note would be neither quick to fill nor
 * classifiable afterwards.
 *
 * The class comes from the teacher's own timetable ({@see MyClasses}): the route names a day and a period,
 * and only a class the teacher really gives then can be planned. That is also the whole access rule —
 * there is no id to guess, and the plan is read and written only for the signed-in teacher.
 *
 * Voluntary: nothing in the application requires a plan. What it gives back is continuity ("where did I
 * leave it with this group"), a one-tap "seguimos igual" for the ordinary class, and — later — the day's
 * guardia task written by itself when the teacher is absent.
 */
#[Route('/mis-clases')]
final class LessonPlanController extends AbstractController
{
    /** How many classes of the group the strip shows at once. */
    private const STRIP_SIZE = 5;

    public function __construct(
        private readonly MyClasses $myClasses,
        private readonly LessonPlanRepository $plans,
        private readonly TopicCatalog $topics,
        private readonly EntityManagerInterface $entityManager,
        private readonly LessonShift $shift,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/{fecha}/{tramo}', name: 'lesson_plan', requirements: ['fecha' => '\d{4}-\d{2}-\d{2}', 'tramo' => '\d+'], methods: ['GET', 'POST'])]
    public function plan(string $fecha, int $tramo, Request $request, #[CurrentUser] User $user): Response
    {
        [$day, $class] = $this->classOrFail($user, $fecha, $tramo);
        [$subject, $groups, $level] = $this->identity($class);
        $plan = $this->plans->findForClass($user, $day, $tramo);
        $previous = $this->plans->findPreviousFor($user, $subject, $groups, $day, $tramo);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('lesson_plan', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Token CSRF inválido.');
            }

            $resolveTopic = fn (string $field): ?Topic => '' !== $subject ? $this->topics->resolve($subject, $level, $request->request->getString($field), $user) : null;

            $plan ??= new LessonPlan($user, $day, $tramo, $groups, $subject, $level);
            $plan->planTwo(
                $resolveTopic('tema'),
                LessonActivity::tryFrom($request->request->getString('actividad')),
                LessonOutcome::tryFrom($request->request->getString('resultado')),
                $resolveTopic('tema2'),
                LessonActivity::tryFrom($request->request->getString('actividad2')),
                LessonOutcome::tryFrom($request->request->getString('resultado2')),
                $request->request->getString('nota'),
            );
            $this->flashStored($this->store($plan), 'Clase guardada.');

            return $this->backToClass($day, $tramo);
        }

        $topics = '' !== $subject ? $this->topics->offeredFor($subject, $level) : [];

        // Lo que se propone al abrir una clase sin programar: seguir donde se quedó con este grupo. El
        // tema y la actividad de la última clase, y su línea si quedó algo pendiente.
        $pending = null !== $previous && true === $previous->getOutcome()?->leavesSomethingPending();

        // Hasta dos entradas: la primera es el tema con el que se llega (o el único de la clase), la
        // segunda solo existe cuando esta misma clase, además, empezó otro tema.
        $entries = $plan?->getTopics() ?? [];
        $entry1 = $entries[0] ?? null;
        $entry2 = $entries[1] ?? null;

        return $this->render('lesson_plan/show.html.twig', [
            'day' => $day,
            'class' => $class,
            'subject' => $subject,
            'plan' => $plan,
            'previous' => $previous,
            'pending' => $pending,
            'topicName' => $entry1?->getTopic()?->getName() ?? $previous?->getTopic()?->getName(),
            'activity' => $entry1?->getActivity() ?? $previous?->getActivity(),
            'outcome' => $entry1?->getOutcome(),
            'note' => null !== $plan ? $plan->getNote() : ($pending ? $previous->getNote() : null),
            'progress' => null !== $entry1?->getTopic() ? $this->topics->progressOf($entry1->getTopic(), $topics) : null,
            'topics' => $topics,
            'activities' => LessonActivity::cases(),
            'outcomes' => LessonOutcome::cases(),
            'topicName2' => $entry2?->getTopic()?->getName(),
            'activity2' => $entry2?->getActivity(),
            'outcome2' => $entry2?->getOutcome(),
            'hasSecondTopic' => null !== $entry2,
            'shiftOffer' => null !== $plan ? $this->shift->offer($plan) : null,
            'strip' => $this->strip($user, $day, $class, $groups, $subject, $request->query->getString('desde')),
            'relativeDay' => $this->relativeDay($day),
        ]);
    }

    /**
     * «Seguimos igual»: the ordinary class in one tap — the same topic and activity as the previous class
     * with this group, and its line when something was left over.
     */
    #[Route('/{fecha}/{tramo}/seguimos', name: 'lesson_plan_repeat', requirements: ['fecha' => '\d{4}-\d{2}-\d{2}', 'tramo' => '\d+'], methods: ['POST'])]
    public function repeat(string $fecha, int $tramo, Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('lesson_plan', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        [$day, $class] = $this->classOrFail($user, $fecha, $tramo);
        [$subject, $groups, $level] = $this->identity($class);
        $previous = $this->plans->findPreviousFor($user, $subject, $groups, $day, $tramo);
        if (null === $previous) {
            $this->addFlash('error', 'No hay una clase anterior con este grupo de la que seguir.');

            return $this->redirectToRoute('lesson_plan', ['fecha' => $fecha, 'tramo' => $tramo]);
        }

        $plan = $this->plans->findForClass($user, $day, $tramo) ?? new LessonPlan($user, $day, $tramo, $groups, $subject, $level);
        $plan->continueFrom($previous);
        $this->flashStored($this->store($plan), sprintf('Programada igual que la clase del %s.', $previous->getDate()->format('d/m')));

        return $this->backToClass($day, $tramo);
    }

    /**
     * «Correr las siguientes una clase»: this class was left half done and the next ones with the group
     * were already planned, so they move one class later and the next one carries on from this
     * ({@see LessonShift}).
     */
    #[Route('/{fecha}/{tramo}/correr', name: 'lesson_plan_shift', requirements: ['fecha' => '\d{4}-\d{2}-\d{2}', 'tramo' => '\d+'], methods: ['POST'])]
    public function shift(string $fecha, int $tramo, Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('lesson_plan', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        [$day] = $this->classOrFail($user, $fecha, $tramo);
        $plan = $this->plans->findForClass($user, $day, $tramo) ?? throw $this->createNotFoundException('Esta clase no está programada.');

        $moved = $this->shift->shift($plan);
        0 === $moved
            ? $this->addFlash('warning', 'No había nada que correr: la clase siguiente ya no estaba programada con otra cosa, o ya ha empezado.')
            : $this->addFlash('success', sprintf('%d clase%s corrida%s una clase más tarde. La siguiente sigue con lo que quedó pendiente.', $moved, 1 === $moved ? '' : 's', 1 === $moved ? '' : 's'));

        return $this->backToClass($day, $tramo);
    }

    /**
     * The teacher's class on that day and period, or a 404: only a class they really give can be planned.
     *
     * @param User   $user  the teacher
     * @param string $fecha the day, "YYYY-MM-DD"
     * @param int    $tramo the period
     *
     * @return array{0: \DateTimeImmutable, 1: ClassSession} the day and the class
     */
    private function classOrFail(User $user, string $fecha, int $tramo): array
    {
        $day = CalendarDate::parse($fecha, AppTime::zone());
        $class = null !== $day
            ? array_values(array_filter($this->myClasses->on($user, $day), static fn (ClassSession $c): bool => $c->slotIndex === $tramo))[0] ?? null
            : null;
        if (null === $day || null === $class) {
            throw $this->createNotFoundException('No tienes clase ese día a esa hora.');
        }

        return [$day, $class];
    }

    /**
     * What identifies a class across the weeks: its subject, its groups as shown, and its level (which,
     * with the subject, picks the shared topic list).
     *
     * @param ClassSession $class the class
     *
     * @return array{0: string, 1: string, 2: EducationLevel|null} subject, groups, level
     */
    private function identity(ClassSession $class): array
    {
        $groups = $class->groupNames();

        return [$class->subject(), $groups, GroupCode::level($groups)];
    }

    /**
     * Saves a plan, or drops it when nothing at all was planned: an empty plan is not worth a row.
     *
     * A double tap on a phone sends the same new plan twice, and the second one hits the one-plan-per-class
     * index. That is not an error — the first one saved it — but it may not be what this request said (two
     * tabs with different choices), so the caller says so instead of claiming this one was saved. Nothing
     * else may use the database after it: a failed flush closes the EntityManager.
     *
     * @param LessonPlan $plan the plan
     *
     * @return bool true when this request's plan was stored, false when the class had been saved already
     */
    private function store(LessonPlan $plan): bool
    {
        if ($plan->isEmpty()) {
            if (null !== $plan->getId()) {
                $this->entityManager->remove($plan);
            }
        } else {
            $this->entityManager->persist($plan);
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * The message after saving: the success, or — when another request had just saved that class — a nudge
     * to check that what stayed is what was meant.
     *
     * @param bool   $stored  whether this request's plan was stored
     * @param string $success the success message
     */
    private function flashStored(bool $stored, string $success): void
    {
        $stored
            ? $this->addFlash('success', $success)
            : $this->addFlash('warning', 'Esta clase se acababa de guardar (¿dos toques?). Ábrela para comprobar que quedó como querías.');
    }

    /**
     * Back to the class just saved, and not to the calendar: its strip shows it planned, and the next
     * class with the group is one tap away — planning a week is save, next, save.
     *
     * @param \DateTimeImmutable $day   the day of the class
     * @param int                $tramo its period
     */
    private function backToClass(\DateTimeImmutable $day, int $tramo): Response
    {
        return $this->redirectToRoute('lesson_plan', ['fecha' => $day->format('Y-m-d'), 'tramo' => $tramo]);
    }

    /**
     * A strip of the same class (these groups, this subject), to move between them without going back to
     * the calendar, each saying whether it is planned — what is still to prepare shows at a glance.
     *
     * It holds STILL: it starts at the class named by "desde" (the strip's links carry it), so opening
     * another class of the strip only moves the highlight. Without it, it starts one class before the one
     * open. The arrows page it without opening a class, keeping the edge class as the anchor: from a strip
     * starting on the 28th, "earlier" shows the one ending on the 28th. The plans of the strip come in one
     * query.
     *
     * @param User               $teacher the teacher
     * @param \DateTimeImmutable $day     the day of the class open
     * @param ClassSession       $class   the class open
     * @param string             $groups  its groups as shown
     * @param string             $subject its subject
     * @param string             $from    where the strip starts, "YYYY-MM-DD.slot", or '' for the default
     *
     * @return array{from: string, items: list<array{date: \DateTimeImmutable, slotIndex: int, ordinal: int, planned: bool, current: bool, past: bool, sameDayTwice: bool, newMonth: bool}>, today: array{date: \DateTimeImmutable, slotIndex: int, isToday: bool}|null, earlier: string|null, later: string|null}
     */
    private function strip(User $teacher, \DateTimeImmutable $day, ClassSession $class, string $groups, string $subject, string $from): array
    {
        $isSame = static fn (ClassSession $c): bool => $c->groupNames() === $groups && $c->subject() === $subject;
        $start = $this->stripStart($teacher, $from, $isSame)
            ?? $this->myClasses->preceding($teacher, $day, $class->slotIndex, $isSame, 1)[0]
            ?? ['date' => $day, 'class' => $class];

        $after = $this->myClasses->following($teacher, $start['date'], $start['class']->slotIndex, $isSame, self::STRIP_SIZE);
        $classes = [$start, ...\array_slice($after, 0, self::STRIP_SIZE - 1)];
        $before = $this->myClasses->preceding($teacher, $start['date'], $start['class']->slotIndex, $isSame, self::STRIP_SIZE - 1);

        $plans = $this->plans->findForTeacherBetween($teacher, $classes[0]['date'], end($classes)['date']);
        $perDay = array_count_values(array_map(static fn (array $c): string => $c['date']->format('Y-m-d'), $classes));
        $now = $this->clock->now()->setTimezone($day->getTimezone());
        $key = static fn (array $c): string => $c['date']->format('Y-m-d').'.'.$c['class']->slotIndex;

        $items = [];
        foreach ($classes as $i => $c) {
            $date = $c['date']->format('Y-m-d');
            $items[] = [
                'date' => $c['date'],
                'slotIndex' => $c['class']->slotIndex,
                'ordinal' => $c['class']->ordinal,
                'planned' => isset($plans[$date.'|'.$c['class']->slotIndex]),
                'current' => $date === $day->format('Y-m-d') && $c['class']->slotIndex === $class->slotIndex,
                // Given already: a class never planned there is a record of nothing, not a job to do.
                'past' => ($c['class']->endsAt ?? $c['date']->modify('+1 day')) <= $now,
                'sameDayTwice' => $perDay[$date] > 1,
                // The month is shown on the first class and wherever it changes: a weekly class spans five weeks.
                'newMonth' => 0 === $i || $c['date']->format('n') !== $classes[$i - 1]['date']->format('n'),
            ];
        }

        // «Hoy»: the class of the group today or, without one today, the next one — where the teacher is in
        // time. Not offered when it is the class open and the strip shows it: there is nowhere to go back to.
        $today = $this->myClasses->following($teacher, $now->setTime(0, 0), -1, $isSame, 1)[0] ?? null;
        $todayIsOpen = null !== $today && $today['date']->format('Y-m-d') === $day->format('Y-m-d') && $today['class']->slotIndex === $class->slotIndex;
        $openIsShown = [] !== array_filter($items, static fn (array $item): bool => $item['current']);

        return [
            'from' => $key($start),
            'items' => $items,
            'today' => null !== $today && !($todayIsOpen && $openIsShown) ? [
                'date' => $today['date'],
                'slotIndex' => $today['class']->slotIndex,
                'isToday' => $today['date']->format('Y-m-d') === $now->format('Y-m-d'),
            ] : null,
            // The strip that ends where this one starts, and the one that starts where this one ends.
            'earlier' => [] !== $before ? $key(end($before)) : null,
            'later' => \count($after) === self::STRIP_SIZE ? $key(end($classes)) : null,
        ];
    }

    /**
     * The class a strip starts at, from its "YYYY-MM-DD.slot" key — only when it is really a class of
     * this group, so a stale or hand-made key falls back to the default strip instead of an error.
     *
     * @param User                        $teacher the teacher
     * @param string                      $from    the key, or ''
     * @param callable(ClassSession):bool $isSame  whether a class is the same class
     *
     * @return array{date: \DateTimeImmutable, class: ClassSession}|null the class, or null
     */
    private function stripStart(User $teacher, string $from, callable $isSame): ?array
    {
        if (1 !== preg_match('/^(\d{4}-\d{2}-\d{2})\.(\d+)$/', $from, $m) || null === $date = CalendarDate::parse($m[1], AppTime::zone())) {
            return null;
        }
        foreach ($this->myClasses->on($teacher, $date) as $class) {
            if ($class->slotIndex === (int) $m[2] && $isSame($class)) {
                return ['date' => $date, 'class' => $class];
            }
        }

        return null;
    }

    /**
     * «Hoy», «Mañana» or «Ayer» for the class's day, so moving between classes says where in time you are.
     *
     * @param \DateTimeImmutable $day the day of the class
     *
     * @return string|null the word, or null for any other day
     */
    private function relativeDay(\DateTimeImmutable $day): ?string
    {
        $today = $this->clock->now()->setTimezone($day->getTimezone())->setTime(0, 0);

        return match ((int) $today->diff($day->setTime(0, 0))->format('%r%a')) {
            0 => 'Hoy',
            1 => 'Mañana',
            -1 => 'Ayer',
            default => null,
        };
    }
}
