<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TicIncident;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\IncidentStatus;
use App\Form\TicIncidentType;
use App\Repository\RoomRepository;
use App\Repository\TicIncidentRepository;
use App\Security\Voter\AreaVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The register of TIC faults, which the centre keeps in the Aula Virtual today.
 *
 * The permission is asymmetric on purpose: REPORTING is open to anybody with an account, because the
 * person who finds the projector dead is whoever had class there and making them ask someone else to
 * file it is how faults stop being reported at all. DEALING with them — taking one on, closing it —
 * needs write access on {@see Area::TIC}, which is what the TIC coordination holds.
 */
#[Route('/incidencias')]
final class TicIncidentController extends AbstractController
{
    /**
     * The register. Shows what is still open by default — the list of things to fix, which is what the
     * screen is for — with the whole history one click away.
     */
    #[Route('', name: 'tic_incident_index', methods: ['GET'])]
    public function index(Request $request, TicIncidentRepository $incidents): Response
    {
        $all = 'todas' === $request->query->getString('ver');

        return $this->render('tic/index.html.twig', [
            'incidents' => $incidents->findForList(!$all),
            'showingAll' => $all,
            'canHandle' => $this->isGranted(AreaVoter::WRITE, Area::TIC),
        ]);
    }

    /**
     * Reports an incident. Open to any signed-in user; the reporter is stamped from the session and
     * never taken from the form, so nobody files one in somebody else's name.
     */
    #[Route('/nueva', name: 'tic_incident_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, RoomRepository $rooms, EntityManagerInterface $entityManager): Response
    {
        $incident = new TicIncident();
        $form = $this->createForm(TicIncidentType::class, $incident, ['rooms' => $rooms->findAllOrdered()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $incident->setReportedBy($user);
            $entityManager->persist($incident);
            $entityManager->flush();
            $this->addFlash('success', 'Incidencia registrada. Queda en la lista hasta que se resuelva.');

            return $this->redirectToRoute('tic_incident_show', ['id' => $incident->getId()]);
        }

        return $this->render('tic/new.html.twig', ['form' => $form]);
    }

    /**
     * One incident, with what was done about it. Readable by anybody: knowing that the projector of the
     * aula 12 is already reported is what stops the fifth duplicate.
     */
    #[Route('/{id}', name: 'tic_incident_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(TicIncident $incident): Response
    {
        return $this->render('tic/show.html.twig', [
            'incident' => $incident,
            'canHandle' => $this->isGranted(AreaVoter::WRITE, Area::TIC),
        ]);
    }

    /**
     * Moves an incident along: take it on, close it (with what was done) or reopen it. Reserved to
     * whoever handles TIC — reporting is everybody's, fixing is not.
     */
    #[Route('/{id}/estado', name: 'tic_incident_move', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function move(TicIncident $incident, Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::TIC);
        if (!$this->isCsrfTokenValid('tic_incident_move'.$incident->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $status = IncidentStatus::tryFrom((string) $request->request->get('estado'));
        if (null === $status) {
            throw $this->createNotFoundException('Ese estado no existe.');
        }

        $note = trim((string) $request->request->get('nota'));
        if (IncidentStatus::RESOLVED === $status && '' === $note) {
            // Cerrar sin decir qué se hizo deja el registro inservible: dentro de un mes nadie sabrá si se
            // arregló, se cambió el aparato o se decidió que no tenía arreglo.
            $this->addFlash('error', 'Escribe qué se ha hecho antes de darla por resuelta.');

            return $this->redirectToRoute('tic_incident_show', ['id' => $incident->getId()]);
        }

        $incident->moveTo($status, $user, $note);
        $entityManager->flush();
        $this->addFlash('success', match ($status) {
            IncidentStatus::IN_PROGRESS => 'Anotado: estás en ello.',
            IncidentStatus::RESOLVED => 'Incidencia resuelta.',
            IncidentStatus::OPEN => 'Vuelve a la lista de pendientes.',
        });

        return $this->redirectToRoute('tic_incident_show', ['id' => $incident->getId()]);
    }
}
