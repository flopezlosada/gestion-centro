<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\Room;
use App\Entity\SpacePlan;
use App\Entity\SpacePlanActivity;
use App\Entity\SpacePlanAssignment;
use App\Entity\SpacePlanOption;
use App\Entity\User;
use App\Enum\Area;
use App\Form\SpacePlanActivityType;
use App\Form\SpacePlanType;
use App\Repository\AcademicYearRepository;
use App\Repository\RoomRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\SpacePlanRepository;
use App\Security\Voter\AreaVoter;
use App\Space\SpacePlanWorkflow;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Space plans: the workflow the centre asked for, once instead of three times — state what the event
 * occupies, let the engine propose several alternatives, compare them, retouch by hand, approve.
 *
 * Everything here needs write access on {@see Area::ESPACIOS}: a plan decides where groups go. Reading
 * an approved plan is what the (later) published document is for, so nothing here is read-only.
 *
 * The one invariant worth repeating: a plan changes nothing anybody can see until it is approved. Until
 * then the alternatives are just rows in a table.
 */
#[Route('/espacios/planes')]
final class SpacePlanController extends AbstractController
{
    #[Route('', name: 'space_plan_index', methods: ['GET'])]
    public function index(SpacePlanRepository $plans, AcademicYearRepository $years): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);

        $year = $years->findBySchoolYear(SchoolYear::current(new \DateTimeImmutable('today')));

        return $this->render('space/plan/index.html.twig', [
            'plans' => null !== $year ? $plans->findForYear($year) : [],
            'year' => $year,
        ]);
    }

    #[Route('/nuevo', name: 'space_plan_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        AcademicYearRepository $years,
        ScheduleEntryRepository $schedule,
        EntityManagerInterface $em,
        #[CurrentUser] User $user,
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);

        $year = $years->findBySchoolYear(SchoolYear::current(new \DateTimeImmutable('today')));
        if (null === $year) {
            $this->addFlash('error', 'No hay un curso con horario importado para hoy: no se puede planificar sobre un horario que no existe.');

            return $this->redirectToRoute('space_plan_index');
        }

        $plan = (new SpacePlan())
            ->setAcademicYear($year)
            ->setCreatedBy($user)
            ->setDateFrom(new \DateTimeImmutable('today'))
            ->setDateTo(new \DateTimeImmutable('today'));

        return $this->handlePlanForm($plan, $request, $schedule, $em);
    }

    #[Route('/{id}/editar', name: 'space_plan_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(SpacePlan $plan, Request $request, ScheduleEntryRepository $schedule, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);
        $this->assertEditable($plan);

        return $this->handlePlanForm($plan, $request, $schedule, $em);
    }

    /**
     * The plan's own page: what it occupies, the alternatives and their figures, and the lines of the
     * one being looked at.
     */
    #[Route('/{id}', name: 'space_plan_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        SpacePlan $plan,
        Request $request,
        ScheduleEntryRepository $schedule,
        RoomRepository $rooms,
        SpacePlanWorkflow $workflow,
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);

        // Which alternative is being looked at: the chosen one by default, else the first.
        $options = $plan->getOptions()->toArray();
        $shown = $plan->getChosenOption() ?? ($options[0] ?? null);
        $requested = $request->query->getInt('opcion');
        foreach ($options as $option) {
            if ($option->getId() === $requested) {
                $shown = $option;
            }
        }

        return $this->render('space/plan/show.html.twig', [
            'plan' => $plan,
            'options' => $options,
            'shown' => $shown,
            'slotTimes' => $schedule->slotTimes($plan->getAcademicYear()),
            'rooms' => $rooms->findActive(),
            'activityForm' => $this->createForm(SpacePlanActivityType::class, new SpacePlanActivity(), [
                'slot_choices' => $this->slotChoices($schedule, $plan->getAcademicYear()),
                'action' => $this->generateUrl('space_plan_activity_add', ['id' => $plan->getId()]),
            ]),
            // Shown before approving rather than after failing: whoever decides sees the double booking
            // while they can still fix it.
            'clashes' => null !== $shown && $plan->getStatus()->isEditable() ? $workflow->clashes($plan, $shown) : [],
        ]);
    }

    #[Route('/{id}/generar', name: 'space_plan_generate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function generate(SpacePlan $plan, Request $request, SpacePlanWorkflow $workflow): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);
        $this->assertCsrf($request, 'space_plan_generate'.$plan->getId());

        try {
            $options = $workflow->generate($plan);
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('space_plan_show', ['id' => $plan->getId()]);
        }

        $this->addFlash('success', match (\count($options)) {
            0 => 'No hay nada que recolocar: con lo que has indicado, ninguna clase se queda sin aula.',
            1 => 'Solo hay una opción viable con estas restricciones.',
            default => sprintf('%d opciones generadas. Compara las cifras antes de elegir.', \count($options)),
        });

        return $this->redirectToRoute('space_plan_show', ['id' => $plan->getId()]);
    }

    #[Route('/{id}/aprobar', name: 'space_plan_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approve(
        SpacePlan $plan,
        Request $request,
        SpacePlanWorkflow $workflow,
        EntityManagerInterface $em,
        #[CurrentUser] User $user,
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);
        $this->assertCsrf($request, 'space_plan_approve'.$plan->getId());

        $option = $em->find(SpacePlanOption::class, $request->request->getInt('option'));
        if (null === $option) {
            $this->addFlash('error', 'Elige una propuesta antes de aprobar.');

            return $this->redirectToRoute('space_plan_show', ['id' => $plan->getId()]);
        }

        try {
            $workflow->approve($plan, $option, $user);
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('space_plan_show', ['id' => $plan->getId(), 'opcion' => $option->getId()]);
        }

        $this->addFlash('success', sprintf('«%s» aprobado. Ya manda sobre el horario en esas fechas.', $plan->getTitle()));

        return $this->redirectToRoute('space_plan_show', ['id' => $plan->getId()]);
    }

    #[Route('/{id}/anular', name: 'space_plan_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(SpacePlan $plan, Request $request, SpacePlanWorkflow $workflow): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);
        $this->assertCsrf($request, 'space_plan_cancel'.$plan->getId());

        $workflow->cancel($plan);
        $this->addFlash('success', 'Plan anulado: deja de afectar al horario. Si ya se había avisado a alguien, díselo.');

        return $this->redirectToRoute('space_plan_show', ['id' => $plan->getId()]);
    }

    /**
     * Adds something the event occupies. Kept on the plan's own page rather than a screen of its own:
     * writing the enunciado and reading what it displaces is one task.
     */
    #[Route('/{id}/actividad', name: 'space_plan_activity_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addActivity(SpacePlan $plan, Request $request, ScheduleEntryRepository $schedule, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);
        $this->assertEditable($plan);

        $activity = new SpacePlanActivity();
        $form = $this->createForm(SpacePlanActivityType::class, $activity, ['slot_choices' => $this->slotChoices($schedule, $plan->getAcademicYear())]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Revisa los datos de la actividad.');

            return $this->redirectToRoute('space_plan_show', ['id' => $plan->getId()]);
        }

        $plan->addActivity($activity);
        $em->persist($activity);
        $em->flush();
        $this->addFlash('success', 'Actividad añadida. Vuelve a generar las propuestas para tenerla en cuenta.');

        return $this->redirectToRoute('space_plan_show', ['id' => $plan->getId()]);
    }

    #[Route('/{id}/actividad/{activityId}/borrar', name: 'space_plan_activity_delete', requirements: ['id' => '\d+', 'activityId' => '\d+'], methods: ['POST'])]
    public function deleteActivity(SpacePlan $plan, int $activityId, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);
        $this->assertEditable($plan);
        $this->assertCsrf($request, 'space_plan_activity_delete'.$activityId);

        $activity = $em->find(SpacePlanActivity::class, $activityId);
        if (null !== $activity && $activity->getPlan() === $plan) {
            $plan->removeActivity($activity);
            $em->remove($activity);
            $em->flush();
            $this->addFlash('success', 'Actividad quitada. Vuelve a generar las propuestas.');
        }

        return $this->redirectToRoute('space_plan_show', ['id' => $plan->getId()]);
    }

    /**
     * Changes where one line goes — the manual edit the centre asked for, on top of what the engine
     * proposed. The line is marked as touched by hand so regenerating will not undo it.
     */
    #[Route('/{id}/linea/{assignmentId}', name: 'space_plan_line_edit', requirements: ['id' => '\d+', 'assignmentId' => '\d+'], methods: ['POST'])]
    public function editLine(
        SpacePlan $plan,
        int $assignmentId,
        Request $request,
        EntityManagerInterface $em,
        SpacePlanWorkflow $workflow,
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);
        $this->assertEditable($plan);
        $this->assertCsrf($request, 'space_plan_line_edit'.$assignmentId);

        $assignment = $em->find(SpacePlanAssignment::class, $assignmentId);
        if (null === $assignment || $assignment->getOption()->getPlan() !== $plan) {
            throw $this->createNotFoundException('Esa línea no es de este plan.');
        }

        $roomId = $request->request->getInt('room');
        $assignment->setRoom(0 === $roomId ? null : $em->find(Room::class, $roomId));
        $assignment->setNote($request->request->getString('note') ?: null);
        $workflow->markEdited($assignment);
        $em->flush();

        $this->addFlash('success', 'Línea cambiada a mano. Al volver a generar propuestas, esta opción se respeta.');

        return $this->redirectToRoute('space_plan_show', ['id' => $plan->getId(), 'opcion' => $assignment->getOption()->getId()]);
    }

    /**
     * Renders and processes the plan's own form.
     *
     * @param SpacePlan               $plan     the plan being created or edited
     * @param Request                 $request  the current request
     * @param ScheduleEntryRepository $schedule the timetable, for the period and group choices
     * @param EntityManagerInterface  $em       the entity manager
     *
     * @return Response the form page, or a redirect to the plan on success
     */
    private function handlePlanForm(SpacePlan $plan, Request $request, ScheduleEntryRepository $schedule, EntityManagerInterface $em): Response
    {
        $year = $plan->getAcademicYear();
        $form = $this->createForm(SpacePlanType::class, $plan, [
            'slot_choices' => $this->slotChoices($schedule, $year),
            'group_choices' => array_combine($schedule->distinctGroupNames($year), $schedule->distinctGroupNames($year)),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($plan);
            $em->flush();
            $this->addFlash('success', 'Plan guardado. Añade lo que ocupa el evento y genera las propuestas.');

            return $this->redirectToRoute('space_plan_show', ['id' => $plan->getId()]);
        }

        return $this->render('space/plan/form.html.twig', [
            'form' => $form,
            'plan' => $plan,
        ]);
    }

    /**
     * The course's periods as form choices, labelled by their times ("3.ª — 10:15-11:10").
     *
     * @param ScheduleEntryRepository $schedule the timetable repository
     * @param AcademicYear            $year     the course
     *
     * @return array<string, int> label → period index
     */
    private function slotChoices(ScheduleEntryRepository $schedule, AcademicYear $year): array
    {
        $choices = [];
        foreach ($schedule->distinctSlots($year) as $slot) {
            $choices[sprintf('%d.ª — %s-%s', $slot['index'] + 1, $slot['startsAt']->format('H:i'), $slot['endsAt']->format('H:i'))] = $slot['index'];
        }

        return $choices;
    }

    /**
     * Refuses to touch a plan that is already decided.
     *
     * @param SpacePlan $plan the plan
     */
    private function assertEditable(SpacePlan $plan): void
    {
        if (!$plan->getStatus()->isEditable()) {
            throw $this->createAccessDeniedException('Este plan ya está aprobado o anulado: no se puede modificar.');
        }
    }

    /**
     * Validates a CSRF token or denies access.
     *
     * @param Request $request the current request
     * @param string  $id      the token id
     */
    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }
}
