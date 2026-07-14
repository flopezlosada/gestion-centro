<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\NonLectiveDay;
use App\Enum\Area;
use App\Form\NonLectiveDayType;
use App\Repository\NonLectiveDayRepository;
use App\Security\Voter\AreaVoter;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin management of the school's non-teaching days (holidays, festivities, one-off closures) that,
 * together with weekends, block task deadlines and are marked on the calendar. Every change is
 * captured automatically by the {@see \App\EventSubscriber\EntityAuditSubscriber} (the entity is
 * {@see \App\Contract\Auditable}), so no manual audit call is needed here. Gated per action by write
 * permission on the {@see Area::ADMINISTRATION} area.
 */
#[Route('/admin/dias-no-lectivos')]
final class AdminNonLectiveDayController extends AbstractController
{
    /**
     * Lists the non-teaching days grouped by school year (most recent course first), earliest day
     * first within each course. A day's course is derived from its date ({@see SchoolYear::current}),
     * so no stored year is needed.
     */
    #[Route('', name: 'admin_non_lective_day_index', methods: ['GET'])]
    public function index(NonLectiveDayRepository $days): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        // findAllOrdered() comes earliest-first, so each group keeps that order; krsort puts the newest
        // course on top.
        $byYear = [];
        foreach ($days->findAllOrdered() as $day) {
            $byYear[SchoolYear::current($day->getDate())][] = $day;
        }
        krsort($byYear);

        return $this->render('admin/non_lective_day/index.html.twig', [
            'groups' => $byYear,
        ]);
    }

    /**
     * Registers a new non-teaching day.
     */
    #[Route('/nuevo', name: 'admin_non_lective_day_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm(new NonLectiveDay(), $request, $em);
    }

    /**
     * Edits an existing non-teaching day.
     */
    #[Route('/{id}/editar', name: 'admin_non_lective_day_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(NonLectiveDay $day, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm($day, $request, $em);
    }

    /**
     * Deletes a non-teaching day. Unlike units, these carry no history to preserve and nothing
     * references them, so a physical delete is the right operation.
     */
    #[Route('/{id}/borrar', name: 'admin_non_lective_day_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(NonLectiveDay $day, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        if (!$this->isCsrfTokenValid('non_lective_day_delete'.$day->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $em->remove($day);
        $em->flush();
        $this->addFlash('success', 'Día no lectivo eliminado.');

        return $this->redirectToRoute('admin_non_lective_day_index');
    }

    /**
     * Renders and processes the create/edit form, persisting on a valid submit.
     *
     * @param NonLectiveDay          $day     the non-teaching day being created or edited
     * @param Request                $request the current request
     * @param EntityManagerInterface $em      the entity manager
     *
     * @return Response the form page, or a redirect to the list on success
     */
    private function handleForm(NonLectiveDay $day, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        $form = $this->createForm(NonLectiveDayType::class, $day);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($day);
            $em->flush();

            $this->addFlash('success', 'Día no lectivo guardado.');

            return $this->redirectToRoute('admin_non_lective_day_index');
        }

        return $this->render('admin/non_lective_day/form.html.twig', [
            'form' => $form,
            'day' => $day,
        ]);
    }
}
