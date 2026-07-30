<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Enum\Area;
use App\Form\ProjectType;
use App\Repository\MeetingRepository;
use App\Repository\ProjectRepository;
use App\Security\Voter\AreaVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin management of the centre's {@see Project}s: who coordinates each one and which teachers make it
 * up. Auto-audited via the {@see \App\EventSubscriber\EntityAuditSubscriber}. Gated per action by write
 * permission on {@see Area::ADMINISTRATION} — like every other /admin controller, and NOT by
 * security.yaml, which leaves /admin at ROLE_USER.
 *
 * Projects live here (next to departments) and not in the meetings module because they are a catalogue
 * the direction team curates, not something a coordinator creates for themselves.
 */
#[Route('/admin/proyectos')]
final class AdminProjectController extends AbstractController
{
    #[Route('', name: 'admin_project_index', methods: ['GET'])]
    public function index(ProjectRepository $projects): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        return $this->render('admin/project/index.html.twig', [
            'projects' => $projects->findAllOrdered(),
        ]);
    }

    #[Route('/nuevo', name: 'admin_project_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm(new Project(), $request, $em);
    }

    #[Route('/{id}/editar', name: 'admin_project_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Project $project, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm($project, $request, $em);
    }

    /**
     * The project's own record: who is in it and every meeting held for it, with its minutes. This is
     * where the direction team reads what a project has been doing.
     */
    #[Route('/{id}', name: 'admin_project_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Project $project, MeetingRepository $meetings): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        return $this->render('admin/project/show.html.twig', [
            'project' => $project,
            'meetings' => $meetings->findForProject($project),
        ]);
    }

    #[Route('/{id}/borrar', name: 'admin_project_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Project $project, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        if (!$this->isCsrfTokenValid('project_delete'.$project->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        // The meetings survive with project_id set to NULL (ON DELETE SET NULL): an acta is a record of
        // what was agreed and must not disappear because the project was tidied up. Retiring the project
        // (active = false) is the non-destructive option and the form says so.
        $em->remove($project);
        $em->flush();
        $this->addFlash('success', 'Proyecto borrado.');

        return $this->redirectToRoute('admin_project_index');
    }

    /**
     * Renders and processes the create/edit form, persisting on a valid submit.
     *
     * @param Project                $project the project being created or edited
     * @param Request                $request the current request
     * @param EntityManagerInterface $em      the entity manager
     *
     * @return Response the form page, or a redirect to the list on success
     */
    private function handleForm(Project $project, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($project);
            $em->flush();
            $this->addFlash('success', 'Proyecto guardado.');

            return $this->redirectToRoute('admin_project_index');
        }

        return $this->render('admin/project/form.html.twig', [
            'form' => $form,
            'project' => $project,
        ]);
    }
}
