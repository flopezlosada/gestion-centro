<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EventCategory;
use App\Enum\Area;
use App\Form\EventCategoryType;
use App\Repository\EventCategoryRepository;
use App\Security\Voter\AreaVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin management of the centre-wide event categories that colour-code personal agenda events. A
 * shared catalogue (unlike the private events that use it). Auto-audited via the
 * {@see \App\EventSubscriber\EntityAuditSubscriber}. Gated per action by write permission on the
 * {@see Area::ADMINISTRATION} area. Deleting a category leaves its events uncategorised (the FK is
 * ON DELETE SET NULL), so a physical delete is safe.
 */
#[Route('/admin/categorias-evento')]
final class AdminEventCategoryController extends AbstractController
{
    #[Route('', name: 'admin_event_category_index', methods: ['GET'])]
    public function index(EventCategoryRepository $categories): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        return $this->render('admin/event_category/index.html.twig', [
            'categories' => $categories->findAllOrdered(),
        ]);
    }

    #[Route('/nueva', name: 'admin_event_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm(new EventCategory(), $request, $em);
    }

    #[Route('/{id}/editar', name: 'admin_event_category_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(EventCategory $category, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm($category, $request, $em);
    }

    #[Route('/{id}/borrar', name: 'admin_event_category_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(EventCategory $category, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        if (!$this->isCsrfTokenValid('event_category_delete'.$category->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $em->remove($category);
        $em->flush();
        $this->addFlash('success', 'Categoría eliminada.');

        return $this->redirectToRoute('admin_event_category_index');
    }

    /**
     * Renders and processes the create/edit form, persisting on a valid submit.
     *
     * @param EventCategory          $category the category being created or edited
     * @param Request                $request  the current request
     * @param EntityManagerInterface $em       the entity manager
     *
     * @return Response the form page, or a redirect to the list on success
     */
    private function handleForm(EventCategory $category, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        $form = $this->createForm(EventCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($category);
            $em->flush();
            $this->addFlash('success', 'Categoría guardada.');

            return $this->redirectToRoute('admin_event_category_index');
        }

        return $this->render('admin/event_category/form.html.twig', [
            'form' => $form,
            'category' => $category,
        ]);
    }
}
