<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\GuardiaGrouping;
use App\Entity\GuardiaSupport;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\Weekday;
use App\Guardia\FreeRooms;
use App\Repository\AcademicYearRepository;
use App\Repository\GuardiaCoverRepository;
use App\Repository\GuardiaGroupingRepository;
use App\Repository\GuardiaSupportRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Service\GuardiaRoomChangeNotifier;
use App\Support\GuardiaDate;
use App\Util\SchoolYear;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What the coordinator does when there are not enough people: sign a colleague up as guardia support for
 * one day and period, and look up which rooms are free to send several groups to one of them.
 *
 * A surface of its own rather than more routes on {@see GuardiaController}, which is already the longest
 * controller in the app. Same gates as the rest of the coordinator's screens ({@see AreaVoter::READ} to
 * look, {@see AreaVoter::WRITE} to change) and the same CSRF discipline; every action lands back on the
 * parte for the day and period it was launched from.
 */
#[Route('/guardias')]
final class GuardiaDeficitController extends AbstractController
{
    /**
     * The "aulas libres" sheet for a day: period by period, which rooms nobody is teaching in, biggest
     * first. Printable for the noticeboard, and the same figures the grouping screen offers as options.
     * Read access is enough — it says nothing private.
     */
    #[Route('/aulas', name: 'guardia_rooms', methods: ['GET'])]
    public function rooms(Request $request, ScheduleEntryRepository $schedule, AcademicYearRepository $years, FreeRooms $freeRooms): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::GUARDIAS);

        $date = GuardiaDate::fromRequest($request);
        $schoolYear = SchoolYear::current($date);
        $year = $years->findBySchoolYear($schoolYear);
        $weekday = Weekday::from((int) $date->format('N'));

        $slots = $year instanceof AcademicYear ? $schedule->distinctSlots($year) : [];
        $slotIndexes = array_map(static fn (array $s): int => $s['index'], $slots);

        return $this->render('guardia/rooms.html.twig', [
            'date' => $date,
            'weekday' => $weekday,
            'schoolYear' => $schoolYear,
            'slots' => $slots,
            'free' => $year instanceof AcademicYear ? $freeRooms->freeBySlot($year, $weekday, $slotIndexes) : [],
        ]);
    }

    /**
     * Signs a colleague up as guardia support for one day and period — the teacher freed by their
     * Bachillerato or CFGB group having finished lessons. Deliberately does NOT check the timetable:
     * it will normally say the teacher is teaching, and only a person knows better (the form says so).
     */
    #[Route('/apoyo', name: 'guardia_support_add', methods: ['POST'])]
    public function addSupport(Request $request, UserRepository $users, GuardiaSupportRepository $support, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_support_add');

        $date = GuardiaDate::fromRequest($request);
        $slotIndex = (int) $request->request->get('slot');
        $teacher = $users->find((int) $request->request->get('teacher'));
        if (!$teacher instanceof User) {
            $this->addFlash('error', 'Elige el profesor que va a hacer el apoyo.');

            return $this->backToParte($date, $slotIndex);
        }

        // Already arranged: the UNIQUE index would reject it anyway, but saying so beats an error page —
        // two coordinators looking at the same shortage is exactly when this gets clicked twice.
        if (null !== $support->findOneBy(['teacher' => $teacher, 'date' => $date, 'slotIndex' => $slotIndex])) {
            $this->addFlash('warning', sprintf('%s ya estaba dado de alta como apoyo en esta hora.', $teacher->getFullName()));

            return $this->backToParte($date, $slotIndex);
        }

        $entry = (new GuardiaSupport())
            ->setTeacher($teacher)
            ->setDate($date)
            ->setSlotIndex($slotIndex)
            ->setNote((string) $request->request->get('note'));

        try {
            $em->persist($entry);
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // Lost the race against another coordinator: the arrangement exists, which is what was wanted.
            $this->addFlash('warning', sprintf('%s ya estaba dado de alta como apoyo en esta hora.', $teacher->getFullName()));

            return $this->backToParte($date, $slotIndex);
        }

        $this->addFlash('success', sprintf('%s queda disponible como apoyo en esta hora. Pulsa «Repartir» para contar con él.', $teacher->getFullName()));

        return $this->backToParte($date, $slotIndex);
    }

    /**
     * Cancels a support arrangement. Guardias already assigned to that teacher are NOT touched: they were
     * covered, the parte says who did it, and silently unassigning somebody who has already been told
     * would be worse than leaving the arrangement visible.
     */
    #[Route('/apoyo/{id}/quitar', name: 'guardia_support_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function removeSupport(GuardiaSupport $entry, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_support_remove'.$entry->getId());

        $date = $entry->getDate();
        $slotIndex = $entry->getSlotIndex();
        $name = $entry->getTeacher()->getFullName();

        $em->remove($entry);
        $em->flush();
        $this->addFlash('success', sprintf('%s ya no figura como apoyo en esta hora.', $name));

        return $this->backToParte($date, $slotIndex);
    }

    /**
     * The "agrupar en un aula" screen: pick which of the period's classes go together, and where. Offers
     * every room with its state (see {@see FreeRooms}) — including the taken ones, because freeing up the
     * library or the assembly hall is the whole point — plus somewhere to send the class being displaced.
     */
    #[Route('/agrupar', name: 'guardia_grouping_new', methods: ['GET'])]
    public function newGrouping(Request $request, GuardiaCoverRepository $covers, GuardiaGroupingRepository $groupings, ScheduleEntryRepository $schedule, AcademicYearRepository $years, FreeRooms $freeRooms): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);

        $date = GuardiaDate::fromRequest($request);
        $schoolYear = SchoolYear::current($date);
        $year = $years->findBySchoolYear($schoolYear);
        $weekday = Weekday::from((int) $date->format('N'));
        $slotIndex = (int) $request->query->get('slot');
        $parte = $covers->findForParte($date, $slotIndex);

        return $this->render('guardia/grouping_new.html.twig', [
            'date' => $date,
            'weekday' => $weekday,
            'schoolYear' => $schoolYear,
            'slotIndex' => $slotIndex,
            'slotLabel' => $this->slotLabel($schedule, $year, $slotIndex),
            // Only the ungrouped lines can be picked; the ones already sorted out are listed apart with
            // their room, so the screen never offers to put the same class in two rooms at once.
            'covers' => array_values(array_filter($parte, static fn (GuardiaCover $c): bool => null === $c->getGrouping())),
            'grouped' => array_values(array_filter($parte, static fn (GuardiaCover $c): bool => null !== $c->getGrouping())),
            'groupings' => $groupings->findForSlot($date, $slotIndex),
            'rooms' => $year instanceof AcademicYear ? $freeRooms->atSlot($year, $weekday, $slotIndex) : [],
        ]);
    }

    /**
     * Creates the grouping and tells everybody it touches: the guardia teachers who will mind the groups
     * together, and — when the room chosen was in use — the colleague whose class has to make way.
     */
    #[Route('/agrupar', name: 'guardia_grouping_create', methods: ['POST'])]
    public function createGrouping(Request $request, GuardiaCoverRepository $covers, ScheduleEntryRepository $schedule, AcademicYearRepository $years, FreeRooms $freeRooms, GuardiaRoomChangeNotifier $notifier, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_grouping_create');

        $date = GuardiaDate::fromRequest($request);
        $slotIndex = (int) $request->request->get('slot');
        $room = trim((string) $request->request->get('room'));
        $coverIds = array_map(intval(...), $request->request->all('covers'));

        if ('' === $room) {
            $this->addFlash('error', 'Elige el aula donde se juntan los grupos.');

            return $this->backToGrouping($date, $slotIndex);
        }
        // Two lines is the least that can be "grouped"; with one there is nothing to join and the room
        // change belongs on the cover itself, not here.
        if (\count($coverIds) < 2) {
            $this->addFlash('error', 'Marca al menos dos grupos para juntarlos en un aula.');

            return $this->backToGrouping($date, $slotIndex);
        }

        // Re-read the lines from the database rather than trusting the posted ids: only lines of THIS
        // date and period, and only ones not already grouped, may go in.
        $selected = array_values(array_filter(
            $covers->findForParte($date, $slotIndex),
            static fn (GuardiaCover $c): bool => \in_array($c->getId(), $coverIds, true) && null === $c->getGrouping(),
        ));
        if (\count($selected) < 2) {
            $this->addFlash('error', 'Esos grupos ya no se pueden juntar: alguno está ya agrupado o no es de esta hora.');

            return $this->backToGrouping($date, $slotIndex);
        }

        $year = $years->findBySchoolYear(SchoolYear::current($date));
        if (!$year instanceof AcademicYear) {
            $this->addFlash('error', sprintf('No hay horario importado para el curso %s.', SchoolYear::current($date)));

            return $this->backToParte($date, $slotIndex);
        }
        $weekday = Weekday::from((int) $date->format('N'));

        // Both rooms must be ones the timetable knows — exactly what the form offered. This is what stops
        // a typo or a hand-made request from inventing a room (and from overflowing the column, which
        // would blow up as a 500 rather than as a message).
        $known = $schedule->distinctRooms($year);
        if (!\in_array($room, $known, true)) {
            $this->addFlash('error', sprintf('«%s» no es un aula del horario del centro.', $room));

            return $this->backToGrouping($date, $slotIndex);
        }
        $displacedTo = trim((string) $request->request->get('displaced_to'));
        if ('' !== $displacedTo && !\in_array($displacedTo, $known, true)) {
            $this->addFlash('error', sprintf('«%s» no es un aula del horario del centro.', $displacedTo));

            return $this->backToGrouping($date, $slotIndex);
        }

        $displaced = $freeRooms->classesIn($year, $weekday, $slotIndex, $room);

        $grouping = (new GuardiaGrouping())
            ->setDate($date)
            ->setSlotIndex($slotIndex)
            ->setRoomName($room)
            ->setDisplacedToRoom($displacedTo)
            ->setNote((string) $request->request->get('note'));

        try {
            $em->persist($grouping);
            foreach ($selected as $cover) {
                $cover->setGrouping($grouping);
            }
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', sprintf('Ya hay una agrupación en %s a esta hora. Deshazla antes de crear otra.', $room));

            return $this->backToGrouping($date, $slotIndex);
        }

        // Notices only once the arrangement is committed: telling somebody their class moves and then
        // failing to save it would be the worst of both worlds.
        $timeLabel = $this->slotLabel($schedule, $year, $slotIndex);
        $notifier->notifyGrouped($grouping, $selected, $timeLabel);
        $warned = $notifier->notifyDisplaced($grouping, $displaced, $covers->absentTeacherIdsAt($date, $slotIndex), $timeLabel);

        $this->addFlash('success', sprintf(
            '%d grupos juntos en %s.%s',
            \count($selected),
            $room,
            $warned > 0 ? sprintf(' Se ha avisado a %d docente(s) del cambio de aula.', $warned) : '',
        ));

        return $this->backToParte($date, $slotIndex);
    }

    /**
     * Undoes a grouping: the classes go back to their own rooms and whoever was displaced is told the
     * change is off. The parte lines themselves are untouched — the grouping never owned anything.
     */
    #[Route('/agrupacion/{id}/deshacer', name: 'guardia_grouping_undo', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function undoGrouping(GuardiaGrouping $grouping, Request $request, GuardiaCoverRepository $covers, ScheduleEntryRepository $schedule, AcademicYearRepository $years, FreeRooms $freeRooms, GuardiaRoomChangeNotifier $notifier, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_grouping_undo'.$grouping->getId());

        $date = $grouping->getDate();
        $slotIndex = $grouping->getSlotIndex();
        $year = $years->findBySchoolYear(SchoolYear::current($date));
        $weekday = Weekday::from((int) $date->format('N'));

        // Who to un-warn, worked out BEFORE the row goes: whoever was teaching in the room it took over,
        // warned or not about a destination (a grouping with nowhere to send them still displaced them).
        $displaced = $year instanceof AcademicYear ? $freeRooms->classesIn($year, $weekday, $slotIndex, $grouping->getRoomName()) : [];
        $timeLabel = $this->slotLabel($schedule, $year, $slotIndex);
        $absentIds = $covers->absentTeacherIdsAt($date, $slotIndex);

        // ON DELETE SET NULL clears the covers' grouping_id, but the lines loaded in this request would
        // still point at a deleted row, so they are unhooked in memory too.
        foreach ($covers->findForParte($date, $slotIndex) as $cover) {
            if ($cover->getGrouping()?->getId() === $grouping->getId()) {
                $cover->setGrouping(null);
            }
        }
        $em->remove($grouping);
        $em->flush();

        // The notice goes out only once the undo is committed. The removed entity still reads correctly
        // for it: Doctrine nulls the generated id on delete but leaves every other field alone, and the
        // message only needs the date, the room and the note.
        $notifier->notifyDisplacementCancelled($grouping, $displaced, $absentIds, $timeLabel);
        $this->addFlash('success', 'Agrupación deshecha. Cada grupo vuelve a su aula.');

        return $this->backToParte($date, $slotIndex);
    }

    /**
     * The period's time range as "08:25–09:20", or null when the course has no imported timetable and the
     * times are therefore unknown — never a made-up ordinal.
     *
     * @param ScheduleEntryRepository $schedule  the timetable repository
     * @param AcademicYear|null       $year      the course the date falls into, if any
     * @param int                     $slotIndex the period index within the day
     *
     * @return string|null the time range, or null if unknown
     */
    private function slotLabel(ScheduleEntryRepository $schedule, ?AcademicYear $year, int $slotIndex): ?string
    {
        $times = $schedule->slotTimes($year)[$slotIndex] ?? null;

        return null !== $times ? $times['startsAt']->format('H:i').'–'.$times['endsAt']->format('H:i') : null;
    }

    /**
     * Redirects back to the grouping screen for a date and period, keeping what was being worked on.
     *
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index
     *
     * @return Response the redirect
     */
    private function backToGrouping(\DateTimeImmutable $date, int $slotIndex): Response
    {
        return $this->redirectToRoute('guardia_grouping_new', ['date' => $date->format('Y-m-d'), 'slot' => $slotIndex]);
    }

    /**
     * Validates the CSRF token for an action or denies access.
     *
     * @param Request $request the current request
     * @param string  $id      the CSRF token id
     */
    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }

    /**
     * Redirects back to the parte for a date and period.
     *
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index
     *
     * @return Response the redirect
     */
    private function backToParte(\DateTimeImmutable $date, int $slotIndex): Response
    {
        return $this->redirectToRoute('guardia_index', ['date' => $date->format('Y-m-d'), 'slot' => $slotIndex]);
    }
}
