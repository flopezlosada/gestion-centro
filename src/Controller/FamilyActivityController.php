<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agenda\ClassSession;
use App\Entity\FamilyActivity;
use App\Entity\FamilySignup;
use App\Entity\OneOffFamilyActivity;
use App\Entity\User;
use App\Entity\WeeklyFamilyActivity;
use App\Enum\Weekday;
use App\Family\FamilyActivityMailer;
use App\Family\FamilySessions;
use App\Form\FamilyActivityFormData;
use App\Form\FamilyActivityFormType;
use App\Form\OneOffFamilyActivityFormData;
use App\Form\WeeklyFamilyActivityFormData;
use App\Repository\FamilyActivityRepository;
use App\Repository\FamilyAvailabilityRepository;
use App\Security\Voter\ModuleVoter;
use App\Util\CalendarDate;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The staff side of the activities open to families (interactive groups, workshops…): any teacher can
 * publish one — in one of their classes every week, or once on a day of its own — and only its organiser,
 * or an admin, who takes over the ones left without organiser, can change or remove it.
 *
 * Staff only, like the rest of the app (security.yaml): what families see of these activities is a
 * screen of their own. Here a teacher sees only what they organise, and the names of the people coming
 * only on the detail of their own activities.
 */
#[Route('/actividades-familias')]
// Off unless the centre turns it on in Datos del centro. On the class, so every page of the module — and
// any added later — answers 404 while it is off, not just the menu link.
#[IsGranted(ModuleVoter::FAMILY_ACTIVITIES, statusCode: 404)]
final class FamilyActivityController extends AbstractController
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly FamilySessions $sessions,
        private readonly FamilyActivityMailer $familyMailer,
    ) {
    }

    /**
     * A week of sessions as a calendar, the activities to manage, and when families can usually come —
     * to choose the class or the day of the next one: how many each day and their hours, never who.
     * A teacher sees only the activities they organise; an admin, every one in the centre.
     *
     * Each weekly activity in the list shows its next session, which reads its organiser's timetable: a
     * few queries per weekly activity, of which a centre has a handful.
     */
    #[Route('', name: 'family_activity_index', methods: ['GET'])]
    public function index(Request $request, FamilyActivityRepository $activities, FamilyAvailabilityRepository $availability, #[CurrentUser] User $user): Response
    {
        $today = $this->clock->now();
        $all = $this->isGranted('ROLE_ADMIN');
        $visible = static fn (FamilyActivity $a): bool => $all || $a->isOrganizedBy($user);
        $asked = CalendarDate::parse($request->query->getString('semana'), $today->getTimezone());
        $monday = null === $asked ? FamilySessions::currentMonday($today) : FamilySessions::mondayOf($asked);

        return $this->render('family_activity/index.html.twig', [
            'monday' => $monday,
            'currentMonday' => FamilySessions::currentMonday($today),
            'week' => $this->sessions->week($monday, $all ? null : $user),
            'now' => $today,
            'weekly' => array_map(
                fn (WeeklyFamilyActivity $a): array => ['activity' => $a, 'next' => $this->sessions->nextOf($a, $today)],
                array_values(array_filter($activities->findWeeklyIn([SchoolYear::current($today)]), $visible)),
            ),
            'oneOffs' => array_values(array_filter($activities->findUpcomingOneOffs($today), $visible)),
            'availability' => $availability->findAllByWeekday(),
            'weekdays' => Weekday::schoolWeek(),
        ]);
    }

    /**
     * One activity in detail, with who comes to each session: upcoming ones first, past ones apart. Only its
     * organiser, or an admin, may open it — the names are for whoever has to organise the session.
     *
     * The e-mail of each person is left out: to know who comes, the name is enough.
     */
    #[Route('/{id}', name: 'family_activity_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, FamilyActivityRepository $activities, #[CurrentUser] User $user): Response
    {
        $activity = $activities->findWithSignups($id) ?? throw $this->createNotFoundException();
        $this->denyUnlessManages($activity, $user);
        $now = $this->clock->now();
        $today = $now->format('Y-m-d');
        $byDate = [];
        foreach ($activity->getSignups() as $signup) {
            $byDate[$signup->getSessionDate()->format('Y-m-d')][] = $signup->getFamily()->getFullName();
        }
        ksort($byDate);

        return $this->render('family_activity/show.html.twig', [
            'activity' => $activity,
            'next' => $activity instanceof WeeklyFamilyActivity ? $this->sessions->nextOf($activity, $now) : null,
            'upcoming' => array_filter($byDate, static fn (string $date): bool => $date >= $today, \ARRAY_FILTER_USE_KEY),
            'past' => array_reverse(array_filter($byDate, static fn (string $date): bool => $date < $today, \ARRAY_FILTER_USE_KEY), true),
        ]);
    }

    /**
     * Publishes a one-off activity, on a day and time of its own. The organiser is stamped from the
     * session, never taken from the form.
     */
    #[Route('/nueva', name: 'family_activity_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        $data = new OneOffFamilyActivityFormData();
        $form = $this->createForm(FamilyActivityFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $activity = new OneOffFamilyActivity($user, $data->title, $this->startOf($data));
            $this->applyOneOff($activity, $data);
            $entityManager->persist($activity);
            $entityManager->flush();
            $this->addFlash('success', 'Actividad publicada.');

            return $this->redirectToRoute('family_activity_index');
        }

        return $this->render('family_activity/new.html.twig', ['form' => $form, 'weekly' => false]);
    }

    /**
     * Publishes a weekly activity in some of the teacher's own classes with one group, picked from their timetable.
     */
    #[Route('/nueva-en-clase', name: 'family_activity_new_weekly', methods: ['GET', 'POST'])]
    public function newWeekly(Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        $classes = $this->sessions->classesOf($user, $this->clock->now());
        $data = new WeeklyFamilyActivityFormData();
        $form = $this->createForm(FamilyActivityFormType::class, $data, ['class_choices' => $this->classChoices($classes)]);
        $form->handleRequest($request);
        $picked = $this->pickedClasses($form, $classes, $data->classKeys);

        if ($form->isSubmitted() && $form->isValid()) {
            $activity = new WeeklyFamilyActivity($user, $data->title, $picked);
            $data->applyDetailsTo($activity);
            $entityManager->persist($activity);
            $entityManager->flush();
            $this->addFlash('success', 'Actividad publicada: tendrá sesión cada semana que des esas clases.');

            return $this->redirectToRoute('family_activity_index');
        }

        return $this->render('family_activity/new.html.twig', ['form' => $form, 'weekly' => true, 'hasClasses' => [] !== $classes, 'classGrid' => $this->classGrid($classes)]);
    }

    /**
     * Edits an activity, of either kind. Only its organiser, or an admin, may reach it.
     */
    #[Route('/{id}/editar', name: 'family_activity_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(FamilyActivity $activity, Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        $this->denyUnlessManages($activity, $user);

        if ($activity instanceof WeeklyFamilyActivity) {
            return $this->editWeekly($activity, $request, $entityManager);
        }
        \assert($activity instanceof OneOffFamilyActivity);
        $data = OneOffFamilyActivityFormData::fromActivity($activity);
        $form = $this->createForm(FamilyActivityFormType::class, $data);
        $form->handleRequest($request);

        return $this->handleEdit($activity, $form, $entityManager, fn () => $this->applyOneOff($activity, $data));
    }

    /**
     * Removes an activity. Only its organiser, or an admin, may do it.
     */
    #[Route('/{id}/borrar', name: 'family_activity_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(FamilyActivity $activity, Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('family_activity_delete'.$activity->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $this->denyUnlessManages($activity, $user);

        $coming = $this->familyMailer->snapshot($activity);
        $entityManager->remove($activity);
        $entityManager->flush();
        $this->familyMailer->mailCancellation($activity, $coming);
        $this->addFlash('success', 'Actividad borrada.');

        return $this->redirectToRoute('family_activity_index');
    }

    /**
     * Edits a weekly activity. It offers its organiser's classes; its own classes stay on offer even when
     * the timetable no longer has them, so saving the rest does not force a change. Changing the classes
     * takes only ones still in the timetable.
     *
     * @param WeeklyFamilyActivity   $activity      the activity
     * @param Request                $request       the current request
     * @param EntityManagerInterface $entityManager the entity manager
     *
     * @return Response the form, or a redirect once saved
     */
    private function editWeekly(WeeklyFamilyActivity $activity, Request $request, EntityManagerInterface $entityManager): Response
    {
        $organizer = $activity->getOrganizer();
        $classes = null !== $organizer ? $this->sessions->classesOf($organizer, $this->clock->now()) : [];
        $current = WeeklyFamilyActivityFormData::keysOf($activity);
        // At one of its days and periods the timetable may now hold another class (other groups, after an
        // import): that one is not offered under the activity's key, which stays the activity's own, gone.
        $classes = array_filter($classes, static fn (array $c, string $key): bool => !\in_array($key, $current, true) || $activity->isHeldIn($c['class'], $c['day']), \ARRAY_FILTER_USE_BOTH);
        $choices = $this->classChoices($classes);
        $gone = [];
        foreach ($activity->classTimes() as $time) {
            $key = $time['weekday']->value.'-'.$time['slot'];
            if (!isset($classes[$key])) {
                $gone[] = $key;
                $choices[$time['weekday']->label().' · '.$activity->getGroupNames().' · '.$time['subject'].' (ya no está en el horario)'] = $key;
            }
        }
        $data = WeeklyFamilyActivityFormData::fromActivity($activity);
        $form = $this->createForm(FamilyActivityFormType::class, $data, ['class_choices' => $choices]);
        $form->handleRequest($request);
        $changed = self::sorted($data->classKeys) !== self::sorted($current);
        if ($changed && $form->isSubmitted() && [] !== array_intersect($data->classKeys, $gone)) {
            $form->get('classKeys')->addError(new FormError('Para cambiar las clases, desmarca las que ya no están en el horario.'));
        }
        $picked = $changed ? $this->pickedClasses($form, $classes, $data->classKeys) : [];

        return $this->handleEdit($activity, $form, $entityManager, static function () use ($activity, $data, $changed, $picked): void {
            $data->applyDetailsTo($activity);
            if ($changed) {
                $activity->holdIn($picked);
            }
        }, ['classGrid' => $this->classGrid($classes)]);
    }

    /**
     * The classes ticked in a weekly activity's form, checked against the activity's own rule
     * ({@see WeeklyFamilyActivity::problemWith()}): what breaks it comes back as an error on the field.
     *
     * @param FormInterface<FamilyActivityFormData>                         $form    the form, already handled
     * @param array<string, array{day: \DateTimeImmutable, class: ClassSession}> $classes the classes on offer, by key
     * @param list<string>                                                  $keys    the keys ticked
     *
     * @return list<array{day: \DateTimeImmutable, class: ClassSession}> the classes ticked that are on offer
     */
    private function pickedClasses(FormInterface $form, array $classes, array $keys): array
    {
        $picked = array_values(array_intersect_key($classes, array_flip($keys)));
        $problem = WeeklyFamilyActivity::problemWith($picked);
        if (null !== $problem && $form->isSubmitted() && $form->isValid()) {
            $form->get('classKeys')->addError(new FormError($problem));
        }

        return $picked;
    }

    /**
     * @param list<string> $keys some keys
     *
     * @return list<string> the same, in order, to compare two choices regardless of the order ticked
     */
    private static function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }

    /**
     * What editing either kind shares, once the form is handled: apply it when valid, save, tell the
     * families whose session changed ({@see FamilyActivityMailer}) and go back to the list.
     *
     * @param FamilyActivity         $activity      the activity
     * @param FormInterface<FamilyActivityFormData> $form its form, already handled
     * @param EntityManagerInterface $entityManager the entity manager
     * @param callable(): void       $apply         copies the validated data onto the activity
     * @param array<string, mixed>   $view          what else the form needs to be drawn
     *
     * @return Response the form, or a redirect once saved
     */
    private function handleEdit(FamilyActivity $activity, FormInterface $form, EntityManagerInterface $entityManager, callable $apply, array $view = []): Response
    {
        if ($form->isSubmitted() && $form->isValid()) {
            $coming = $this->familyMailer->snapshot($activity);
            $apply();
            $entityManager->flush();
            $this->familyMailer->mailChanges($activity, $coming);
            $this->addFlash('success', 'Actividad actualizada.');

            return $this->redirectToRoute('family_activity_index');
        }

        $today = $this->clock->now()->setTime(0, 0);

        return $this->render('family_activity/edit.html.twig', [
            'form' => $form,
            'activity' => $activity,
            'comingFromToday' => \count(array_filter($activity->getSignups(), static fn (FamilySignup $s): bool => $s->getSessionDate() >= $today)),
            ...$view,
        ]);
    }

    /**
     * The teacher's classes laid out as their timetable, for picking one: a row per period, a column per
     * school day, each class under its "weekday-period" key.
     *
     * @param array<string, array{day: \DateTimeImmutable, class: ClassSession}> $classes from {@see FamilySessions::classesOf()}
     *
     * @return array{days: array<int, string>, rows: list<array{period: string, times: string, cells: array<int, array{key: string, groups: string, subject: string}>}>}
     *               the day names by ISO weekday, and the periods earliest first
     */
    private function classGrid(array $classes): array
    {
        $rows = [];
        foreach ($classes as $key => ['day' => $day, 'class' => $class]) {
            $rows[$class->slotIndex] ??= [
                'period' => $class->ordinal.'ª',
                'times' => null !== $class->startsAt && null !== $class->endsAt ? $class->startsAt->format('H:i').'–'.$class->endsAt->format('H:i') : '',
                'cells' => [],
            ];
            $rows[$class->slotIndex]['cells'][(int) $day->format('N')] = ['key' => (string) $key, 'groups' => $class->groupNames(), 'subject' => $class->subject()];
        }
        ksort($rows);

        return [
            'days' => array_combine(
                array_map(static fn (Weekday $w): int => $w->value, Weekday::schoolWeek()),
                array_map(static fn (Weekday $w): string => $w->label(), Weekday::schoolWeek()),
            ),
            'rows' => array_values($rows),
        ];
    }

    /**
     * The teacher's classes as choices: "Martes · 3ª (10:15–11:10) · 1ºA · Matemáticas" => "2-2".
     *
     * @param array<string, array{day: \DateTimeImmutable, class: ClassSession}> $classes from {@see FamilySessions::classesOf()}
     *
     * @return array<string, string> label => "weekday-period" key
     */
    private function classChoices(array $classes): array
    {
        $choices = [];
        foreach ($classes as $key => ['day' => $day, 'class' => $class]) {
            $times = null !== $class->startsAt && null !== $class->endsAt ? ' ('.$class->startsAt->format('H:i').'–'.$class->endsAt->format('H:i').')' : '';
            $label = implode(' · ', array_filter([
                Weekday::from((int) $day->format('N'))->label(),
                $class->ordinal.'ª'.$times,
                $class->groupNames(),
                $class->subject(),
            ], static fn (string $part): bool => '' !== $part));
            $choices[$label] = (string) $key;
        }

        return $choices;
    }

    /**
     * Copies the validated form data onto a one-off activity.
     *
     * @param OneOffFamilyActivity         $activity the activity to update
     * @param OneOffFamilyActivityFormData $data     the validated form data
     */
    private function applyOneOff(OneOffFamilyActivity $activity, OneOffFamilyActivityFormData $data): void
    {
        \assert(null !== $data->day);
        $data->applyDetailsTo($activity);
        $activity->reschedule(
            $this->startOf($data),
            null !== $data->endTime ? CalendarDate::at($data->day, $data->endTime) : null,
        );
    }

    /**
     * The start instant: the chosen day at the chosen time. Both are required by the form's validation.
     *
     * @param OneOffFamilyActivityFormData $data the validated form data
     *
     * @return \DateTimeImmutable when the activity starts
     */
    private function startOf(OneOffFamilyActivityFormData $data): \DateTimeImmutable
    {
        \assert(null !== $data->day && null !== $data->startTime);

        return CalendarDate::at($data->day, $data->startTime);
    }

    /**
     * Denies access unless the user organises the activity or is an admin — the single gate for changing
     * one.
     *
     * @param FamilyActivity $activity the activity being acted on
     * @param User           $user     the current user
     */
    private function denyUnlessManages(FamilyActivity $activity, User $user): void
    {
        if (!$activity->isOrganizedBy($user) && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Esta actividad la organiza otra persona.');
        }
    }
}
