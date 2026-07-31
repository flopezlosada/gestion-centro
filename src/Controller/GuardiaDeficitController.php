<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\GuardiaGrouping;
use App\Entity\GuardiaSupport;
use App\Entity\Room;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\Weekday;
use App\Repository\AbsenceRepository;
use App\Repository\AcademicYearRepository;
use App\Repository\GuardiaCoverRepository;
use App\Repository\GuardiaGroupingRepository;
use App\Repository\GuardiaSupportRepository;
use App\Repository\RoomRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Service\GuardiaRoomChangeNotifier;
use App\Space\RoomAvailability;
use App\Space\RoomOccupancy;
use App\Space\RoomOccupation;
use App\Space\RoomSynchroniser;
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
    use GuardiaParteTrait;

    /**
     * The "aulas libres" sheet for a day: period by period, which spaces nobody is using, biggest first.
     * Printable for the noticeboard, and the same figures the grouping screen offers as options. Read
     * access is enough — it says nothing private.
     *
     * Answers on the EFFECTIVE timetable ({@see RoomOccupancy}), the same service the Espacios module
     * uses: a room an approved space plan has just taken must not appear here as free, or two groups end
     * up in it. Under the guardia gate rather than the Espacios one, because this is the coordinator's
     * sheet and they may well have no permission on the spaces module.
     */
    #[Route('/aulas', name: 'guardia_rooms', methods: ['GET'])]
    public function rooms(Request $request, ScheduleEntryRepository $schedule, AcademicYearRepository $years, RoomOccupancy $occupancy, RoomSynchroniser $synchroniser): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::GUARDIAS);

        $date = GuardiaDate::fromRequest($request);
        $schoolYear = SchoolYear::current($date);
        $year = $years->findBySchoolYear($schoolYear);

        $slots = $year instanceof AcademicYear ? $schedule->distinctSlots($year) : [];
        $slotIndexes = array_map(static fn (array $s): int => $s['index'], $slots);
        $availability = $year instanceof AcademicYear ? $occupancy->forDay($year, $date, $slotIndexes) : [];

        return $this->render('guardia/rooms.html.twig', [
            'date' => $date,
            'schoolYear' => $schoolYear,
            'slots' => $slots,
            'free' => array_map(static fn (RoomAvailability $a): array => $a->largestFirst(), $availability),
            // A timetable cell naming a room with no catalogued card is invisible to the calculation,
            // which would report that room as free. Say so instead of letting it be found out as two
            // groups sent to the same place.
            'unlinkedCells' => $synchroniser->unlinkedCells(),
        ]);
    }

    /**
     * Signs a colleague up as guardia support for one day and period — the teacher freed by their
     * Bachillerato or CFGB group having finished lessons. Deliberately does NOT check the timetable:
     * it will normally say the teacher is teaching, and only a person knows better (the form says so).
     */
    #[Route('/apoyo', name: 'guardia_support_add', methods: ['POST'])]
    public function addSupport(Request $request, UserRepository $users, GuardiaSupportRepository $support, ScheduleEntryRepository $schedule, AcademicYearRepository $years, EntityManagerInterface $em): Response
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

        // Somebody already on the rota that hour gains nothing from being signed up: the engine would
        // count them once anyway (as a guardia, which wins), and the parte would then show a rota row with
        // no way to undo the arrangement. Refused with an explanation rather than stored as a no-op.
        $year = $years->findBySchoolYear(SchoolYear::current($date));
        if ($year instanceof AcademicYear && $this->isOnDuty($schedule, $year, $date, $slotIndex, $teacher)) {
            $this->addFlash('warning', sprintf('%s ya tiene guardia a esta hora en su horario: no hace falta darle de alta como apoyo.', $teacher->getFullName()));

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
     * every space with its state ({@see RoomOccupancy}) — including the taken ones, because freeing up the
     * library or the assembly hall is the whole point — plus somewhere to send the class being displaced.
     */
    #[Route('/agrupar', name: 'guardia_grouping_new', methods: ['GET'])]
    public function newGrouping(Request $request, GuardiaCoverRepository $covers, GuardiaGroupingRepository $groupings, ScheduleEntryRepository $schedule, AcademicYearRepository $years, RoomOccupancy $occupancy): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);

        $date = GuardiaDate::fromRequest($request);
        $schoolYear = SchoolYear::current($date);
        $year = $years->findBySchoolYear($schoolYear);
        $slotIndex = (int) $request->query->get('slot');
        $parte = $covers->findForParte($date, $slotIndex);
        $availability = $year instanceof AcademicYear ? $occupancy->at($year, $date, $slotIndex) : null;

        return $this->render('guardia/grouping_new.html.twig', [
            'date' => $date,
            'schoolYear' => $schoolYear,
            'slotIndex' => $slotIndex,
            'slotLabel' => $this->slotLabel($schedule, $year, $slotIndex),
            // Only the ungrouped lines can be picked; the ones already sorted out are listed apart with
            // their room, so the screen never offers to put the same class in two rooms at once.
            'covers' => array_values(array_filter($parte, static fn (GuardiaCover $c): bool => null === $c->getGrouping())),
            'grouped' => array_values(array_filter($parte, static fn (GuardiaCover $c): bool => null !== $c->getGrouping())),
            'groupings' => $groupings->findForSlot($date, $slotIndex),
            // Biggest first, which is what is being looked for; the occupied ones apart, because choosing
            // one of those means displacing somebody and the screen has to say who.
            'rooms' => null !== $availability ? $availability->largestFirst() : [],
            'occupied' => null !== $availability ? $availability->occupiedLargestFirst() : [],
            // And the free spaces the catalogue says no group may be SENT to, apart and last. Minding
            // three groups in the gym for one period is not the same as relocating a lesson there, and
            // hiding it would take away the roomiest thing the centre has on a bad morning.
            'notAssignable' => null !== $availability ? $availability->otherFree() : [],
        ]);
    }

    /**
     * Creates the grouping and tells everybody it touches: the guardia teachers who will mind the groups
     * together, and — when the room chosen was in use — the colleague whose class has to make way.
     */
    #[Route('/agrupar', name: 'guardia_grouping_create', methods: ['POST'])]
    public function createGrouping(Request $request, GuardiaCoverRepository $covers, AbsenceRepository $absences, ScheduleEntryRepository $schedule, AcademicYearRepository $years, RoomRepository $rooms, RoomOccupancy $occupancy, GuardiaRoomChangeNotifier $notifier, EntityManagerInterface $em): Response
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
        // Both rooms must be spaces the catalogue holds AND still has in use — exactly what the form
        // offered. This is what stops a typo, a stale form or a hand-made request from inventing a room
        // (or from overflowing the column, which would blow up as a 500 rather than as a message).
        $target = $this->activeRoom($rooms, $room);
        $displacedToCode = trim((string) $request->request->get('displaced_to'));
        $displacedTo = '' !== $displacedToCode ? $this->activeRoom($rooms, $displacedToCode) : null;
        if (null === $target || ('' !== $displacedToCode && null === $displacedTo)) {
            $this->addFlash('error', sprintf('«%s» no es un espacio en uso del centro.', null === $target ? $room : $displacedToCode));

            return $this->backToGrouping($date, $slotIndex);
        }

        // Who is in the chosen room at that moment, on the EFFECTIVE timetable: an approved space plan may
        // have emptied it or filled it, and either way the ordinary timetable alone would answer wrong.
        $occupation = $this->occupationOf($occupancy->at($year, $date, $slotIndex), $target);
        $displaced = null !== $occupation ? $occupation->entries : [];

        $grouping = (new GuardiaGrouping())
            ->setDate($date)
            ->setSlotIndex($slotIndex)
            ->setRoomName($target->getCode())
            ->setDisplacedToRoom($displacedToCode)
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
        $warned = $notifier->notifyDisplaced($grouping, $displaced, $absences->absentTeacherIdsAt($date, $slotIndex), $timeLabel);

        $this->addFlash('success', sprintf(
            '%d grupos juntos en %s.%s',
            \count($selected),
            $target->getCode(),
            $warned > 0 ? sprintf(' Se ha avisado a %d docente(s) del cambio de aula.', $warned) : '',
        ));

        // A room an approved space plan put something in cannot be un-notified automatically: the notices
        // here are built from timetable cells, and a plan line is not one. Said out loud instead of
        // leaving the coordinator thinking everybody has been told.
        if (true === $occupation?->isPlanned()) {
            $this->addFlash('warning', sprintf(
                'Ojo: %s está ocupada a esa hora por un cambio de aula ya aprobado (%s). Avisa a mano a quien corresponda.',
                $target->getCode(),
                $occupation->subjects(),
            ));
        }

        return $this->backToParte($date, $slotIndex);
    }

    /**
     * Undoes a grouping: the classes go back to their own rooms and whoever was displaced is told the
     * change is off. The parte lines themselves are untouched — the grouping never owned anything.
     */
    #[Route('/agrupacion/{id}/deshacer', name: 'guardia_grouping_undo', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function undoGrouping(GuardiaGrouping $grouping, Request $request, GuardiaCoverRepository $covers, AbsenceRepository $absences, ScheduleEntryRepository $schedule, AcademicYearRepository $years, RoomRepository $rooms, RoomOccupancy $occupancy, GuardiaRoomChangeNotifier $notifier, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_grouping_undo'.$grouping->getId());

        $date = $grouping->getDate();
        $slotIndex = $grouping->getSlotIndex();
        $year = $years->findBySchoolYear(SchoolYear::current($date));

        // Who to un-warn, worked out BEFORE the row goes: whoever was teaching in the room it took over,
        // warned or not about a destination (a grouping with nowhere to send them still displaced them).
        // Read on the same effective timetable the warning was built from, so both ends match.
        $room = $rooms->findByCode($grouping->getRoomName());
        $occupation = $year instanceof AcademicYear && null !== $room
            ? $this->occupationOf($occupancy->at($year, $date, $slotIndex), $room)
            : null;
        $displaced = null !== $occupation ? $occupation->entries : [];
        $timeLabel = $this->slotLabel($schedule, $year, $slotIndex);
        $absentIds = $absences->absentTeacherIdsAt($date, $slotIndex);

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
     * A catalogued space that is still in use, by code — what a posted room name has to resolve to.
     *
     * @param RoomRepository $rooms the space catalogue
     * @param string         $code  the code as posted
     *
     * @return Room|null the space, or null when it is unknown or retired
     */
    private function activeRoom(RoomRepository $rooms, string $code): ?Room
    {
        $room = $rooms->findByCode($code);

        return null !== $room && $room->isActive() ? $room : null;
    }

    /**
     * The occupation of one space within a period's answer, or null when nobody is in it.
     *
     * @param RoomAvailability $availability the period's occupancy
     * @param Room             $room         the space to look up
     *
     * @return RoomOccupation|null what is in it, or null if it is free
     */
    private function occupationOf(RoomAvailability $availability, Room $room): ?RoomOccupation
    {
        foreach ($availability->occupied as $occupation) {
            if ($occupation->room->getId() === $room->getId()) {
                return $occupation;
            }
        }

        return null;
    }

    /**
     * Whether a teacher is already on the rota (guardia or collaborator) at that date and period.
     *
     * @param ScheduleEntryRepository $schedule  the timetable repository
     * @param AcademicYear            $year      the course the date falls into
     * @param \DateTimeImmutable      $date      the day
     * @param int                     $slotIndex the period index within the day
     * @param User                    $teacher   the teacher to look for
     *
     * @return bool true when the timetable already puts them on duty then
     */
    private function isOnDuty(ScheduleEntryRepository $schedule, AcademicYear $year, \DateTimeImmutable $date, int $slotIndex, User $teacher): bool
    {
        foreach ($schedule->dutyPoolAt($year, Weekday::from((int) $date->format('N')), $slotIndex) as $entry) {
            if ($entry->getTeacher()->getId() === $teacher->getId()) {
                return true;
            }
        }

        return false;
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

}
