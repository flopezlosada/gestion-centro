<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FamilyVolunteer;
use App\Enum\Area;
use App\Form\FamilyVolunteerFormType;
use App\Repository\FamilyVolunteerRepository;
use App\Security\Voter\AreaVoter;
use App\Security\Voter\ModuleVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The accounts of the families who may sign in to the families' area. Personal data of people outside
 * the staff, so only administration manages them, the same gate as staff accounts.
 *
 * Removing an account is how access is withdrawn: the families' firewall reloads the account on every
 * request, so a removed one is signed out on its next click, and its data goes with it.
 */
#[Route('/actividades-familias/cuentas')]
#[IsGranted(ModuleVoter::FAMILY_ACTIVITIES, statusCode: 404)]
final class FamilyVolunteerController extends AbstractController
{
    #[Route('', name: 'family_volunteer_index', methods: ['GET'])]
    public function index(FamilyVolunteerRepository $families): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        return $this->render('family_volunteer/index.html.twig', ['families' => $families->findAllOrdered()]);
    }

    #[Route('/nueva', name: 'family_volunteer_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm(new FamilyVolunteer(), $request, $em);
    }

    #[Route('/{id}/editar', name: 'family_volunteer_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(FamilyVolunteer $family, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm($family, $request, $em);
    }

    /**
     * Removes the account, which withdraws access and deletes the person's data.
     */
    #[Route('/{id}/borrar', name: 'family_volunteer_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(FamilyVolunteer $family, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('family_volunteer_delete'.$family->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        $em->remove($family);
        $em->flush();
        $this->addFlash('success', 'Cuenta borrada: ya no puede entrar.');

        return $this->redirectToRoute('family_volunteer_index');
    }

    /**
     * Shared create/edit, same shape as the /admin catalogues.
     *
     * @param FamilyVolunteer        $family  the account to create or edit
     * @param Request                $request the current request
     * @param EntityManagerInterface $em      the entity manager
     *
     * @return Response the form, or a redirect once saved
     */
    private function handleForm(FamilyVolunteer $family, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        $form = $this->createForm(FamilyVolunteerFormType::class, $family);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($family);
            $em->flush();
            $this->addFlash('success', 'Cuenta guardada.');

            return $this->redirectToRoute('family_volunteer_index');
        }

        return $this->render('family_volunteer/form.html.twig', ['form' => $form, 'family' => $family]);
    }
}
