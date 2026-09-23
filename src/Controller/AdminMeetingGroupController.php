<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MeetingGroup;
use App\Enum\Area;
use App\Enum\Weekday;
use App\Form\MeetingGroupFormType;
use App\Repository\AcademicYearRepository;
use App\Repository\MeetingGroupRepository;
use App\Repository\TimeSlotRepository;
use App\Security\Voter\AreaVoter;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Los grupos estándar de convocatoria que mantiene el centro: listas guardadas de gente que se convoca
 * junta a menudo (tutores de un nivel, la CCP), para no volver a marcarlos uno a uno cada vez.
 *
 * Mismo patrón que {@see AdminMeetingTypeController} y {@see AdminProjectController}: catálogo editable
 * desde /admin, gateado por escritura en {@see Area::ADMINISTRATION}, con baja lógica en vez de borrado.
 *
 * Aquí se completa lo que Peñalara no sabe de una reunión semanal importada: quién convoca y de qué tipo
 * es. Y si alguien cambia a mano el día, la hora o las personas de un grupo que vino de Peñalara, queda
 * marcado, para que el siguiente import pregunte antes de pisarlo.
 */
#[Route('/admin/grupos-de-reunion')]
final class AdminMeetingGroupController extends AbstractController
{
    #[Route('', name: 'admin_meeting_group_index', methods: ['GET'])]
    public function index(MeetingGroupRepository $groups, AcademicYearRepository $years, TimeSlotRepository $timeSlots): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        return $this->render('admin/meeting_group/index.html.twig', [
            'groups' => $groups->findAllOrdered(),
            'slotLabels' => array_flip($this->slotChoices($years, $timeSlots)),
        ]);
    }

    #[Route('/nuevo', name: 'admin_meeting_group_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, AcademicYearRepository $years, TimeSlotRepository $timeSlots): Response
    {
        return $this->handleForm(new MeetingGroup(), $request, $em, $this->slotChoices($years, $timeSlots));
    }

    #[Route('/{id}/editar', name: 'admin_meeting_group_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(MeetingGroup $group, Request $request, EntityManagerInterface $em, AcademicYearRepository $years, TimeSlotRepository $timeSlots): Response
    {
        return $this->handleForm($group, $request, $em, $this->slotChoices($years, $timeSlots));
    }

    #[Route('/{id}/borrar', name: 'admin_meeting_group_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(MeetingGroup $group, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        if (!$this->isCsrfTokenValid('meeting_group_delete'.$group->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $em->remove($group);
        $em->flush();
        $this->addFlash('success', 'Grupo borrado.');

        return $this->redirectToRoute('admin_meeting_group_index');
    }

    /**
     * Shared create/edit. Same shape as the rest of the /admin catalogues.
     *
     * @param MeetingGroup           $group       the group being created or edited
     * @param Request                $request     the current request
     * @param EntityManagerInterface $em          the entity manager
     * @param array<string, int>     $slotChoices the periods of the day, label → index
     *
     * @return Response the form page, or a redirect to the list on success
     */
    private function handleForm(MeetingGroup $group, Request $request, EntityManagerInterface $em, array $slotChoices): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        // Un grupo con una hora que ya no está en el marco horario del curso la sigue enseñando, en vez
        // de que el formulario la rechace como "valor no válido" y obligue a perderla para poder guardar.
        if (null !== $group->getSlotIndex() && !\in_array($group->getSlotIndex(), $slotChoices, true)) {
            $slotChoices[sprintf('Tramo %d', $group->getSlotIndex())] = $group->getSlotIndex();
        }
        $before = $group->importedShape();

        $form = $this->createForm(MeetingGroupFormType::class, $group, ['slot_choices' => $slotChoices]);
        $form->get('weekday')->setData($group->getWeekday());
        $form->get('slotIndex')->setData($group->getSlotIndex());
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $weekday = $form->get('weekday')->getData();
            $slotIndex = $form->get('slotIndex')->getData();
            if ($weekday instanceof Weekday && null !== $slotIndex) {
                $group->repeatWeekly($weekday, (int) $slotIndex);
            } elseif (null === $weekday && null === $slotIndex) {
                $group->stopRepeating();
            } else {
                $form->get(null === $weekday ? 'weekday' : 'slotIndex')->addError(new FormError('Para que se repita cada semana hacen falta el día y la hora.'));
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            if (null !== $group->getPenalaraKey() && $group->importedShape() !== $before) {
                $group->markEditedSinceImport();
            }
            $em->persist($group);
            $em->flush();
            $this->addFlash('success', 'Grupo guardado.');

            return $this->redirectToRoute('admin_meeting_group_index');
        }

        return $this->render('admin/meeting_group/form.html.twig', [
            'form' => $form,
            'group' => $group,
        ]);
    }

    /**
     * The course's teaching periods for the "Hora" field, named the way the staff name them ("3ª") with
     * their times. Borrows last course's frame before this one's timetable is imported, like bookings do.
     *
     * @param AcademicYearRepository $years     the courses
     * @param TimeSlotRepository     $timeSlots the marco horario
     *
     * @return array<string, int> label → period index
     */
    private function slotChoices(AcademicYearRepository $years, TimeSlotRepository $timeSlots): array
    {
        $slots = $timeSlots->lectiveTimesWithFallback($years->findBySchoolYear(SchoolYear::current(new \DateTimeImmutable('today'))))['slots'];
        ksort($slots);

        $choices = [];
        $ordinal = 0;
        foreach ($slots as $index => $times) {
            $choices[sprintf('%dª · %s–%s', ++$ordinal, $times['startsAt']->format('H:i'), $times['endsAt']->format('H:i'))] = $index;
        }

        return $choices;
    }
}
