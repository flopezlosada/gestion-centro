<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\Area;
use App\Repository\AcademicYearRepository;
use App\Repository\ScheduleEntryRepository;
use App\Security\Voter\AreaVoter;
use App\Service\SchoolCalendar;
use App\Space\RoomOccupancy;
use App\Space\RoomSynchroniser;
use App\Support\SchedulePicker;
use App\Util\SchoolYear;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Aulas libres según el horario": for a chosen day and period, which spaces the timetable puts nobody
 * in and which are taken, by whom.
 *
 * The first screen of the space-management module and the one the guardia coordinator asked for: when
 * there are more absences than teachers on call, several groups are merged into one big room, and
 * finding that room today means asking around. It is a read-only consultation — nothing here books
 * anything; moving a group is what the space plans (next phase) are for.
 *
 * The name is deliberate. The timetable only knows about lessons, so a room held for a meeting shows
 * as free ({@see RoomOccupancy}); the screen says "según el horario" instead of promising more than it
 * can know.
 */
#[Route('/espacios')]
final class SpaceController extends AbstractController
{
    /**
     * The free/occupied spaces at a period, with the same date-and-period picker as the guardia parte.
     */
    #[Route('', name: 'space_index', methods: ['GET'])]
    public function index(
        Request $request,
        AcademicYearRepository $years,
        ScheduleEntryRepository $schedule,
        RoomOccupancy $occupancy,
        RoomSynchroniser $synchroniser,
        SchoolCalendar $calendar,
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::ESPACIOS);

        $date = SchedulePicker::date($request);
        $schoolYear = SchoolYear::current($date);
        $year = $years->findBySchoolYear($schoolYear);

        // Periods and occupancy both come from the timetable of the course the date falls into; with no
        // course imported there is nothing to answer, and the template shows the empty state.
        $slots = null !== $year ? $schedule->distinctSlots($year) : [];
        $slotIndex = SchedulePicker::slot($request, $slots);
        $availability = null !== $year ? $occupancy->at($year, $date, $slotIndex) : null;

        // How many people must fit, when the person asking knows: it filters the candidate list but
        // never hides a room whose capacity nobody has filled in yet.
        $forPeople = max(0, $request->query->getInt('personas')) ?: null;

        return $this->render('space/index.html.twig', [
            'date' => $date,
            'schoolYear' => $schoolYear,
            'slots' => $slots,
            'slotIndex' => $slotIndex,
            'availability' => $availability,
            'candidates' => null !== $availability ? $availability->candidates($forPeople) : [],
            'otherFree' => null !== $availability ? $availability->otherFree($forPeople) : [],
            'forPeople' => $forPeople,
            'isLective' => $calendar->isLective($date),
            // A cell naming a room with no catalogued card is invisible to the occupancy calculation,
            // which would report that room as free. Surface it instead of letting it be discovered as
            // two groups sent to the same place.
            'unlinkedCells' => $synchroniser->unlinkedCells(),
        ]);
    }
}
