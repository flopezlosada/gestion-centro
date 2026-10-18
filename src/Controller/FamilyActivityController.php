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
 * screen of their own, so nothing here has to filter what a family may read.
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
    ) {
    }

    /**
     * A week of sessions as a calendar, the activities to manage, and when families can usually come —
     * to choose the class or the day of the next one: how many each day and their hours, never who.
     *
     * Each weekly activity in the list shows its next session, which reads its organiser's timetable: a
     * few queries per weekly activity, of which a centre has a handful.
     */
    #[Route('', name: 'family_activity_index', methods: ['GET'])]
    public function index(Request $request, FamilyActivityRepository $activities, FamilyAvailabilityRepository $availability): Response
    {
        $today = $this->clock->now();
        $monday = FamilySessions::mondayOf(CalendarDate::parse($request->query->getString('semana'), $today->getTimezone()) ?? $today);

        return $this->render('family_activity/index.html.twig', [
            'monday' => $monday,
            'week' => $this->sessions->week($monday),
            'now' => $today,
            'weekly' => array_map(
                fn (WeeklyFamilyActivity $a): array => ['activity' => $a, 'next' => $this->sessions->nextOf($a, $today)],
                $activities->findWeeklyIn([SchoolYear::current($today)]),
            ),
            'oneOffs' => $activities->findUpcomingOneOffs($today),
            'availability' => $availability->findAllByWeekday(),
            'weekdays' => Weekday::schoolWeek(),
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
     * Publishes a weekly activity in one of the teacher's own classes, picked from their timetable.
     */
    #[Route('/nueva-en-clase', name: 'family_activity_new_weekly', methods: ['GET', 'POST'])]
    public function newWeekly(Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        $classes = $this->sessions->classesOf($user, $this->clock->now());
        $data = new WeeklyFamilyActivityFormData();
        $form = $this->createForm(FamilyActivityFormType::class, $data, ['class_choices' => $this->classChoices($classes)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            ['class' => $class, 'day' => $day] = $classes[$data->classKey];
            $activity = new WeeklyFamilyActivity($user, $data->title, $class, $day);
            $data->applyDetailsTo($activity);
            $entityManager->persist($activity);
            $entityManager->flush();
            $this->addFlash('success', 'Actividad publicada: tendrá sesión cada semana que des esa clase.');

            return $this->redirectToRoute('family_activity_index');
        }

        return $this->render('family_activity/new.html.twig', ['form' => $form, 'weekly' => true, 'hasClasses' => [] !== $classes]);
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

        return $this->handleEdit($activity, $this->createForm(FamilyActivityFormType::class, $data), $request, $entityManager, fn () => $this->applyOneOff($activity, $data));
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

        $entityManager->remove($activity);
        $entityManager->flush();
        $this->addFlash('success', 'Actividad borrada.');

        return $this->redirectToRoute('family_activity_index');
    }

    /**
     * Edits a weekly activity. It offers its organiser's classes; its own class stays on offer even when
     * the timetable no longer has it, so saving the rest does not force a move.
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
        $current = WeeklyFamilyActivityFormData::keyOf($activity);
        // At its day and period the timetable may now hold another class (other groups, after an import):
        // that one is not offered under the activity's key, which stays the activity's own, marked gone.
        if (isset($classes[$current]) && !$activity->isHeldIn($classes[$current]['class'], $classes[$current]['day'])) {
            unset($classes[$current]);
        }
        $choices = $this->classChoices($classes);
        if (!isset($classes[$current])) {
            $choices[$activity->getWeekday()->label().' · '.$activity->getGroupNames().' · '.$activity->getSubject().' (ya no está en el horario)'] = $current;
        }
        $data = WeeklyFamilyActivityFormData::fromActivity($activity);
        $form = $this->createForm(FamilyActivityFormType::class, $data, ['class_choices' => $choices]);

        return $this->handleEdit($activity, $form, $request, $entityManager, static function () use ($activity, $data, $classes, $current): void {
            $data->applyDetailsTo($activity);
            if ($data->classKey !== $current && isset($classes[$data->classKey])) {
                $activity->moveTo($classes[$data->classKey]['class'], $classes[$data->classKey]['day']);
            }
        });
    }

    /**
     * What editing either kind shares: handle the form, apply it when valid, save and go back to the list.
     *
     * @param FamilyActivity         $activity      the activity
     * @param FormInterface<FamilyActivityFormData> $form its form, already built with its data
     * @param Request                $request       the current request
     * @param EntityManagerInterface $entityManager the entity manager
     * @param callable(): void       $apply         copies the validated data onto the activity
     *
     * @return Response the form, or a redirect once saved
     */
    private function handleEdit(FamilyActivity $activity, FormInterface $form, Request $request, EntityManagerInterface $entityManager, callable $apply): Response
    {
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $apply();
            $entityManager->flush();
            $this->addFlash('success', 'Actividad actualizada.');

            return $this->redirectToRoute('family_activity_index');
        }

        $today = $this->clock->now()->setTime(0, 0);

        return $this->render('family_activity/edit.html.twig', [
            'form' => $form,
            'activity' => $activity,
            'comingFromToday' => \count(array_filter($activity->getSignups(), static fn (FamilySignup $s): bool => $s->getSessionDate() >= $today)),
        ]);
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
