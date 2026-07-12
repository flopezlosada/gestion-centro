<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Enum\Area;
use App\Form\AcademicYearType;
use App\Repository\AcademicYearRepository;
use App\Security\Voter\AreaVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin management of the school-year structure: the start and end dates of the three terms for each
 * course. These term boundaries feed the task deadline rules (anchors such as "end of a term" or
 * "before a break") and the yearly task generation. Every change is captured automatically by the
 * {@see \App\EventSubscriber\EntityAuditSubscriber} (the entity is {@see \App\Contract\Auditable}).
 * Gated per action by write permission on the {@see Area::ADMINISTRATION} area.
 */
#[Route('/admin/trimestres')]
final class AdminAcademicYearController extends AbstractController
{
    /**
     * Lists every course whose structure has been set, most recent first.
     */
    #[Route('', name: 'admin_academic_year_index', methods: ['GET'])]
    public function index(AcademicYearRepository $years): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        return $this->render('admin/academic_year/index.html.twig', [
            'years' => $years->findAllOrdered(),
        ]);
    }

    /**
     * Defines the term structure of a new course.
     */
    #[Route('/nuevo', name: 'admin_academic_year_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm(new AcademicYear(), $request, $em);
    }

    /**
     * Edits the term structure of an existing course.
     */
    #[Route('/{id}/editar', name: 'admin_academic_year_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(AcademicYear $year, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm($year, $request, $em);
    }

    /**
     * Deletes a course structure. Tasks reference their course by its "YYYY-YYYY" code, not by a
     * foreign key to this row, so removing it leaves existing tasks untouched; it only drops the term
     * skeleton, which can be re-created.
     */
    #[Route('/{id}/borrar', name: 'admin_academic_year_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(AcademicYear $year, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        if (!$this->isCsrfTokenValid('academic_year_delete'.$year->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $em->remove($year);
        $em->flush();
        $this->addFlash('success', 'Estructura del curso eliminada.');

        return $this->redirectToRoute('admin_academic_year_index');
    }

    /**
     * Renders and processes the create/edit form, persisting on a valid submit.
     *
     * @param AcademicYear           $year    the course structure being created or edited
     * @param Request                $request the current request
     * @param EntityManagerInterface $em      the entity manager
     *
     * @return Response the form page, or a redirect to the list on success
     */
    private function handleForm(AcademicYear $year, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        $form = $this->createForm(AcademicYearType::class, $year);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($year);
            $em->flush();

            $this->addFlash('success', 'Estructura del curso guardada.');

            return $this->redirectToRoute('admin_academic_year_index');
        }

        return $this->render('admin/academic_year/form.html.twig', [
            'form' => $form,
            'year' => $year,
        ]);
    }
}
