<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TaskTemplate;
use App\Enum\Area;
use App\Form\TaskTemplateType;
use App\Repository\TaskTemplateRepository;
use App\Security\Voter\AreaVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin management of the recurring-task catalogue ({@see TaskTemplate}): the templates Direction
 * validates and from which each course's tasks are generated. Includes the optional deadline rule
 * that drives the yearly generation. Every change is captured automatically by the
 * {@see \App\EventSubscriber\EntityAuditSubscriber} (the entity is {@see \App\Contract\Auditable}).
 * Gated per action by write permission on the {@see Area::ADMINISTRATION} area.
 */
#[Route('/admin/catalogo')]
final class AdminTaskTemplateController extends AbstractController
{
    /**
     * Lists every template, active ones first, then by title.
     */
    #[Route('', name: 'admin_task_template_index', methods: ['GET'])]
    public function index(TaskTemplateRepository $templates): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        return $this->render('admin/task_template/index.html.twig', [
            'templates' => $templates->findAllOrdered(),
        ]);
    }

    /**
     * Creates a new template.
     */
    #[Route('/nueva', name: 'admin_task_template_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm(new TaskTemplate(), $request, $em);
    }

    /**
     * Edits an existing template.
     */
    #[Route('/{id}/editar', name: 'admin_task_template_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(TaskTemplate $template, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm($template, $request, $em);
    }

    /**
     * Deletes a template. Instances already created keep their own data (they copy from the template
     * at instantiation), so removing it only drops it from future generation.
     */
    #[Route('/{id}/borrar', name: 'admin_task_template_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(TaskTemplate $template, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        if (!$this->isCsrfTokenValid('task_template_delete'.$template->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $em->remove($template);
        $em->flush();
        $this->addFlash('success', 'Plantilla eliminada.');

        return $this->redirectToRoute('admin_task_template_index');
    }

    /**
     * Renders and processes the create/edit form, persisting on a valid submit.
     *
     * @param TaskTemplate           $template the template being created or edited
     * @param Request                $request  the current request
     * @param EntityManagerInterface $em       the entity manager
     *
     * @return Response the form page, or a redirect to the list on success
     */
    private function handleForm(TaskTemplate $template, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        $form = $this->createForm(TaskTemplateType::class, $template);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($template);
            $em->flush();

            $this->addFlash('success', 'Plantilla guardada.');

            return $this->redirectToRoute('admin_task_template_index');
        }

        return $this->render('admin/task_template/form.html.twig', [
            'form' => $form,
            'template' => $template,
        ]);
    }
}
