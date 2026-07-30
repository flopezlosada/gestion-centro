<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Room;
use App\Enum\Area;
use App\Form\RoomType;
use App\Repository\RoomRepository;
use App\Repository\ScheduleEntryRepository;
use App\Security\Voter\AreaVoter;
use App\Space\RoomSynchroniser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The catalogue of the centre's spaces: the cards the space module reasons over.
 *
 * Cards are mostly DISCOVERED rather than typed in — {@see RoomSynchroniser} creates a stub for every
 * room the imported timetable names — so the work here is completing what the export cannot know
 * (capacity, kind, whether a group may be sent there), not data entry from scratch. The listing puts
 * the incomplete cards first for that reason.
 *
 * Gated by write access on {@see Area::ESPACIOS}: capacity and assignability decide where groups end
 * up, so this is not a read-only catalogue anybody may edit. Auto-audited via the
 * {@see \App\EventSubscriber\EntityAuditSubscriber}.
 */
#[Route('/espacios/catalogo')]
final class SpaceRoomController extends AbstractController
{
    #[Route('', name: 'space_room_index', methods: ['GET'])]
    public function index(RoomRepository $rooms, RoomSynchroniser $synchroniser): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);

        // Incomplete cards first, retired ones last: what this screen is for is finishing the stubs the
        // synchroniser created, and a catalogue sorted purely by code buries them among the done ones.
        // Sorted here rather than in SQL because it is a handful of rows and a CASE in DQL reads worse.
        $catalogue = $rooms->findAllOrdered();
        usort($catalogue, static fn (Room $a, Room $b): int => [!$a->isActive(), !$a->needsReview(), $a->getCode()]
            <=> [!$b->isActive(), !$b->needsReview(), $b->getCode()]);

        return $this->render('space/room/index.html.twig', [
            'rooms' => $catalogue,
            'unlinkedCells' => $synchroniser->unlinkedCells(),
        ]);
    }

    /**
     * Creates the missing cards from the imported timetable, on demand. The import screen already does
     * this on every import; this is the button for a database whose timetable was loaded before the
     * catalogue existed, and the way to recover from a card somebody renamed by hand.
     */
    #[Route('/sincronizar', name: 'space_room_sync', methods: ['POST'])]
    public function sync(Request $request, RoomSynchroniser $synchroniser): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);
        if (!$this->isCsrfTokenValid('space_room_sync', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $result = $synchroniser->sync();
        $this->addFlash('success', $result->isEmpty()
            ? 'El catálogo ya estaba al día: el horario no nombra ningún espacio que falte.'
            : sprintf(
                'Catálogo actualizado: %d espacio(s) nuevo(s)%s y %d celda(s) de horario enlazada(s).',
                \count($result->createdCodes),
                [] === $result->createdCodes ? '' : ' ('.implode(', ', $result->createdCodes).')',
                $result->linkedCells,
            ));

        return $this->redirectToRoute('space_room_index');
    }

    #[Route('/nuevo', name: 'space_room_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm(new Room(), $request, $em);
    }

    #[Route('/{id}/editar', name: 'space_room_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Room $room, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm($room, $request, $em);
    }

    /**
     * Deletes a space — only one the timetable never references. A room in use is deactivated instead
     * (the form has the switch): removing it would blank the {@code room_id} of its cells (the FK is ON
     * DELETE SET NULL) and every one of them would silently stop counting as occupied.
     */
    #[Route('/{id}/borrar', name: 'space_room_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Room $room, Request $request, EntityManagerInterface $em, ScheduleEntryRepository $schedule): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);
        if (!$this->isCsrfTokenValid('space_room_delete'.$room->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $uses = $schedule->countByRoom($room);
        if ($uses > 0) {
            $this->addFlash('warning', sprintf(
                'No se puede borrar «%s»: el horario lo usa en %d clase(s). Desactívalo si ya no se utiliza.',
                $room->getCode(),
                $uses,
            ));

            return $this->redirectToRoute('space_room_index');
        }

        $em->remove($room);
        $em->flush();
        $this->addFlash('success', 'Espacio eliminado.');

        return $this->redirectToRoute('space_room_index');
    }

    /**
     * Renders and processes the create/edit form, persisting on a valid submit.
     *
     * @param Room                   $room    the space being created or edited
     * @param Request                $request the current request
     * @param EntityManagerInterface $em      the entity manager
     *
     * @return Response the form page, or a redirect to the catalogue on success
     */
    private function handleForm(Room $room, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::ESPACIOS);
        $form = $this->createForm(RoomType::class, $room);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($room);
            $em->flush();
            $this->addFlash('success', 'Espacio guardado.');

            return $this->redirectToRoute('space_room_index');
        }

        return $this->render('space/room/form.html.twig', [
            'form' => $form,
            'room' => $room,
        ]);
    }
}
