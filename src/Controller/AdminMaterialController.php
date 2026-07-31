<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Material;
use App\Enum\Area;
use App\Form\MaterialFormType;
use App\Repository\MaterialRepository;
use App\Security\Voter\AreaVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * El catálogo de material reservable: la radio, la cámara, los carros de portátiles, el móvil de
 * extraescolares.
 *
 * A diferencia de los espacios —que se descubren solos del horario importado ({@see RoomSynchroniser})—
 * el material no existe en ningún export, así que alguien lo teclea una vez. Es una lista corta y la
 * rellena administración.
 *
 * No se borra: se marca como retirado. Las reservas ya hechas conservan el nombre de lo que se reservó.
 */
#[Route('/admin/material')]
final class AdminMaterialController extends AbstractController
{
    #[Route('', name: 'admin_material_index', methods: ['GET'])]
    public function index(MaterialRepository $materials): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        return $this->render('admin/material/index.html.twig', ['materials' => $materials->findAllOrdered()]);
    }

    #[Route('/nuevo', name: 'admin_material_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm(new Material(), $request, $em);
    }

    #[Route('/{id}/editar', name: 'admin_material_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Material $material, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm($material, $request, $em);
    }

    /**
     * Shared create/edit, same shape as the rest of the /admin catalogues.
     *
     * @param Material               $material the piece of material to create or edit
     * @param Request                $request  the current request
     * @param EntityManagerInterface $em       the entity manager
     *
     * @return Response the form, or a redirect once saved
     */
    private function handleForm(Material $material, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        $form = $this->createForm(MaterialFormType::class, $material);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($material);
            $em->flush();
            $this->addFlash('success', 'Material guardado.');

            return $this->redirectToRoute('admin_material_index');
        }

        return $this->render('admin/material/form.html.twig', ['form' => $form, 'material' => $material]);
    }
}
