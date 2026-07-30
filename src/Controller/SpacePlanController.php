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
use App\Form\SpaceOccupationBlockType;
use App\Form\SpacePlanType;
use App\Repository\AcademicYearRepository;
use App\Repository\RoomRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\SpacePlanRepository;
use App\Security\Voter\AreaVoter;
use App\Service\SchoolCalendar;
use App\Space\SpacePlanNotifier;
use App\Space\SpacePlanWorkflow;
use App\Space\StaffScheduler;
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
            'activityForm' => $this->createForm(SpaceOccupationBlockType::class, null, [
                'slot_choices' => $this->slotChoices($schedule, $plan->getAcademicYear()),
                'group_choices' => $this->groupChoices($schedule, $plan->getAcademicYear()),
                'action' => $this->generateUrl('space_plan_activity_add', ['id' => $plan->getId()]),
            ]),
            // Shown before approving rather than after failing: whoever decides sees the double booking
            // while they can still fix it.
            'clashes' => null !== $shown && $plan->getStatus()->isEditable() ? $workflow->clashes($plan, $shown) : [],
        ]);
    }

    /**
     * The publishable document: the approved plan laid out to be read on paper, in the three ways the
     * centre actually needs it — by group (for the students), by space (for the doors and conserjería)
     * and by teacher (for the staff room).
     *
     * Open to any signed-in user, deliberately: this is the digital version of the notice board, and it
     * is where the notice each affected teacher receives points. Gating it behind the Espacios area
     * would send them to a 403. Only the equipo directivo may look at one that is not approved yet.
     */
    #[Route('/{id}/documento', name: 'space_plan_document', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function document(SpacePlan $plan, Request $request, ScheduleEntryRepository $schedule): Response
    {
        if (!$plan->getStatus()->isInForce() && !$this->isGranted(AreaVoter::WRITE, Area::ESPACIOS)) {
            throw $this->createNotFoundException('Ese plan todavía no está aprobado.');
        }

        $view = $request->query->getString('vista');
        $view = \in_array($view, ['grupo', 'espacio', 'docente'], true) ? $view : 'grupo';
        $option = $plan->getChosenOption() ?? ($plan->getOptions()->toArray()[0] ?? null);

        return $this->render('space/plan/document.html.twig', [
            'plan' => $plan,
            'option' => $option,
            'view' => $view,
            'sections' => null !== $option ? $this->group($option->getAssignments()->toArray(), $view) : [],
            'slotTimes' => $schedule->slotTimes($plan->getAcademicYear()),
        ]);
    }

    #[Route('/{id}/avisar', name: 'space_plan_notify', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function notify(SpacePlan $plan, Request $request, SpacePlanNotifier $notifier): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);
        $this->assertCsrf($request, 'space_plan_notify'.$plan->getId());

        try {
            $told = $notifier->notify($plan);
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('space_plan_show', ['id' => $plan->getId()]);
        }

        $this->addFlash('success', match ($told) {
            0 => 'No había a quién avisar: ninguna línea tiene un profesor asociado.',
            1 => 'Avisada 1 persona (aviso en la aplicación, correo y móvil).',
            default => sprintf('Avisadas %d personas (aviso en la aplicación, correo y móvil).', $told),
        });

        return $this->redirectToRoute('space_plan_show', ['id' => $plan->getId()]);
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

    /**
     * Shares out who runs each session of a special day, over the alternative being looked at.
     *
     * Only sessions with nobody yet, unless asked to start over: somebody who decided by hand that a
     * workshop is run by a particular person has made a decision, and re-running the rota is no reason
     * to undo it.
     */
    #[Route('/{id}/profesorado', name: 'space_plan_staff', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function shareStaff(SpacePlan $plan, Request $request, StaffScheduler $staff, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);
        $this->assertEditable($plan);
        $this->assertCsrf($request, 'space_plan_staff'.$plan->getId());

        $option = $em->find(SpacePlanOption::class, $request->request->getInt('option'));
        if (null === $option || $option->getPlan() !== $plan) {
            $this->addFlash('error', 'Elige primero la propuesta sobre la que repartir.');

            return $this->redirectToRoute('space_plan_show', ['id' => $plan->getId()]);
        }

        $result = $staff->share($plan, $option, $request->request->getBoolean('startOver'));

        $this->addFlash(0 === $result['assigned'] ? 'warning' : 'success', match (true) {
            0 === $result['assigned'] && 0 === $result['uncovered'] => 'No hay sesiones que repartir: esta propuesta no tiene actividades sin profesor.',
            0 === $result['assigned'] => 'No se ha podido cubrir ninguna sesión: a esas horas no hay nadie cuyo horario lo permita.',
            default => sprintf(
                '%d sesión(es) repartida(s) entre %d persona(s)%s.',
                $result['assigned'],
                $result['people'],
                $result['uncovered'] > 0 ? sprintf('; %d se quedan sin nadie disponible', $result['uncovered']) : '',
            ),
        });

        return $this->redirectToRoute('space_plan_show', ['id' => $plan->getId(), 'opcion' => $option->getId()]);
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
     * Adds what the event occupies, as a block: several rooms, over a range of days, at several periods.
     *
     * One form instead of one per room and day. The centre's exam week takes four rooms for four days,
     * which stated one at a time is sixteen identical forms — and the person filling in the sixteenth
     * makes mistakes. Non-teaching days inside the range are skipped: an occupation on a Saturday would
     * quietly widen every count without changing anything.
     */
    #[Route('/{id}/actividad', name: 'space_plan_activity_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addActivity(
        SpacePlan $plan,
        Request $request,
        ScheduleEntryRepository $schedule,
        SchoolCalendar $calendar,
        EntityManagerInterface $em,
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);
        $this->assertEditable($plan);

        $form = $this->createForm(SpaceOccupationBlockType::class, null, [
            'slot_choices' => $this->slotChoices($schedule, $plan->getAcademicYear()),
            'group_choices' => $this->groupChoices($schedule, $plan->getAcademicYear()),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Revisa los datos: hacen falta un nombre, las fechas y al menos una hora.');

            return $this->redirectToRoute('space_plan_show', ['id' => $plan->getId()]);
        }

        /** @var array{title: string, rooms: iterable<Room>, groups: list<string>, from: \DateTimeImmutable, to: \DateTimeImmutable, slots: list<int>} $data */
        $data = $form->getData();
        // No rooms named means "find one": ONE activity per day, whose room the engine decides. Naming
        // rooms means the event takes those, so there is one activity per room and day.
        $rooms = [...$data['rooms']];
        $targets = [] === $rooms ? [null] : $rooms;

        $created = 0;
        $skippedDays = 0;
        for ($date = $data['from']; $date <= $data['to']; $date = $date->modify('+1 day')) {
            if (!$calendar->isLective($date)) {
                ++$skippedDays;
                continue;
            }

            foreach ($targets as $room) {
                $activity = (new SpacePlanActivity())
                    ->setTitle($data['title'])
                    ->setRoom($room)
                    ->setFixedDate($date)
                    ->setFixedSlots($data['slots'])
                    ->setTargetGroupNames($data['groups']);
                $plan->addActivity($activity);
                $em->persist($activity);
                ++$created;
            }
        }

        $em->flush();
        $this->addFlash('success', sprintf(
            '%d ocupación(es) añadida(s)%s. Vuelve a generar las propuestas para tenerlas en cuenta.',
            $created,
            $skippedDays > 0 ? sprintf(' (%d día(s) no lectivo(s) saltado(s))', $skippedDays) : '',
        ));

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
     * Groups a plan's lines the way one of the three document views reads them: by group, by space or by
     * teacher, each section ordered by day and period.
     *
     * A line with several groups ("E1A, E1B", a split period) appears under EACH of them in the "by
     * group" view: a student looking for their own group must find it, whoever else shares the room.
     *
     * @param list<SpacePlanAssignment> $assignments the lines
     * @param string                    $view        'grupo', 'espacio' or 'docente'
     *
     * @return array<string, list<SpacePlanAssignment>> heading → its lines
     */
    private function group(array $assignments, string $view): array
    {
        $sections = [];
        foreach ($assignments as $assignment) {
            foreach ($this->headingsFor($assignment, $view) as $heading) {
                $sections[$heading][] = $assignment;
            }
        }

        ksort($sections, \SORT_NATURAL | \SORT_FLAG_CASE);
        foreach ($sections as &$lines) {
            usort($lines, static fn (SpacePlanAssignment $a, SpacePlanAssignment $b): int => [$a->getDate(), $a->getSlotIndex()] <=> [$b->getDate(), $b->getSlotIndex()]);
        }

        return $sections;
    }

    /**
     * The headings one line belongs under in a given view.
     *
     * @param SpacePlanAssignment $assignment the line
     * @param string              $view       'grupo', 'espacio' or 'docente'
     *
     * @return list<string> its headings (several only in the "by group" view)
     */
    private function headingsFor(SpacePlanAssignment $assignment, string $view): array
    {
        return match ($view) {
            'espacio' => [$assignment->getRoom()?->getCode() ?? 'Sin aula asignada'],
            'docente' => [$assignment->getTeacher()?->getFullName() ?? 'Sin profesor asignado'],
            default => array_map(
                static fn (string $g): string => trim($g),
                explode(',', $assignment->getGroupNames() ?? ($assignment->getActivityTitle() ?? 'Sin grupo')),
            ),
        };
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
     * The course's groups as form choices, so a workshop's audience is picked from the timetable rather
     * than typed in (a name that does not match exactly would size the room wrong).
     *
     * @param ScheduleEntryRepository $schedule the timetable repository
     * @param AcademicYear            $year     the course
     *
     * @return array<string, string> group name → group name
     */
    private function groupChoices(ScheduleEntryRepository $schedule, AcademicYear $year): array
    {
        $groups = $schedule->distinctGroupNames($year);

        return array_combine($groups, $groups);
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
