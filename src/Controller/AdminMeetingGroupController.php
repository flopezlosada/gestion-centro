<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MeetingGroup;
use App\Enum\Area;
use App\Form\MeetingGroupFormType;
use App\Repository\MeetingGroupRepository;
use App\Security\Voter\AreaVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Los grupos estándar de convocatoria que mantiene el centro: listas guardadas de gente que se convoca
 * junta a menudo (tutores de un nivel, la CCP), para no volver a marcarlos uno a uno cada vez.
 *
 * Mismo patrón que {@see AdminMeetingTypeController} y {@see AdminProjectController}: catálogo editable
 * desde /admin, gateado por escritura en {@see Area::ADMINISTRATION}, con baja lógica en vez de borrado.
 */
#[Route('/admin/grupos-de-reunion')]
final class AdminMeetingGroupController extends AbstractController
{
    #[Route('', name: 'admin_meeting_group_index', methods: ['GET'])]
    public function index(MeetingGroupRepository $groups): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        return $this->render('admin/meeting_group/index.html.twig', ['groups' => $groups->findAllOrdered()]);
    }

    #[Route('/nuevo', name: 'admin_meeting_group_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm(new MeetingGroup(), $request, $em);
    }

    #[Route('/{id}/editar', name: 'admin_meeting_group_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(MeetingGroup $group, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm($group, $request, $em);
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
     * @param MeetingGroup           $group   the group being created or edited
     * @param Request                $request the current request
     * @param EntityManagerInterface $em      the entity manager
     *
     * @return Response the form page, or a redirect to the list on success
     */
    private function handleForm(MeetingGroup $group, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);
        $form = $this->createForm(MeetingGroupFormType::class, $group);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
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
}
