<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MeetingType;
use App\Enum\Area;
use App\Form\MeetingTypeFormType;
use App\Repository\MeetingTypeRepository;
use App\Security\Voter\AreaVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Los tipos de reunión que mantiene el centro: CCP, tutores, equipo directivo, AMPA/AFA, agentes
 * externos y una entrada por cada comisión de trabajo.
 *
 * Existe porque el centro lo pidió con estas palabras: "podemos tener la posibilidad desde administración
 * de modificar estos desplegables y así no depender de ti". Añadir una comisión en octubre no puede ser
 * un despliegue.
 *
 * Un tipo NO se borra: se desactiva. Las actas ya archivadas bajo él conservan su etiqueta, y borrarlo
 * las dejaría sin nombre en el archivo — que es justo donde se van a buscar dentro de dos cursos.
 */
#[Route('/admin/tipos-de-reunion')]
final class AdminMeetingTypeController extends AbstractController
{
    #[Route('', name: 'admin_meeting_type_index', methods: ['GET'])]
    public function index(MeetingTypeRepository $types): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        return $this->render('admin/meeting_type/index.html.twig', ['types' => $types->findAllOrdered()]);
    }

    #[Route('/nuevo', name: 'admin_meeting_type_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm(new MeetingType(), $request, $em);
    }

    #[Route('/{id}/editar', name: 'admin_meeting_type_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(MeetingType $type, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm($type, $request, $em);
    }

    /**
     * Shared create/edit. Same shape as the rest of the /admin catalogues.
     *
     * @param MeetingType            $type    the kind to create or edit
     * @param Request                $request the current request
     * @param EntityManagerInterface $em      the entity manager
     *
     * @return Response the form, or a redirect once saved
     */
    private function handleForm(MeetingType $type, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ADMINISTRATION);

        $form = $this->createForm(MeetingTypeFormType::class, $type);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($type);
            $em->flush();
            $this->addFlash('success', 'Tipo de reunión guardado.');

            return $this->redirectToRoute('admin_meeting_type_index');
        }

        return $this->render('admin/meeting_type/form.html.twig', [
            'form' => $form,
            'type' => $type,
        ]);
    }
}
