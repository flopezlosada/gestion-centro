<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\Area;
use App\Repository\AcademicYearRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\SpacePlanAssignmentRepository;
use App\Security\Voter\AreaVoter;
use App\Service\SchoolCalendar;
use App\Space\RoomOccupancy;
use App\Space\RoomSynchroniser;
use App\Support\GuardiaDate;
use App\Util\SchoolYear;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

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
     * Redirige a LA pantalla de aulas libres, la de guardias.
     *
     * Había dos: esta y `/guardias/aulas`. Las dos contestaban «¿qué aulas quedan libres a esta hora?» y las
     * dos leen ya el mismo {@see RoomOccupancy}, así que no podían discrepar en el dato — pero eran dos sitios
     * que aprender, dos diseños que mantener y dos oportunidades de divergir. Se conserva la de guardias, que
     * está pensada para resolver con prisa (una hora a la vez, con su selector) y es la que rediseñó el
     * handoff. Esta ruta no se borra porque hay enlaces guardados y avisos que apuntan aquí.
     *
     * El módulo de espacios se queda con lo que es suyo: los planes de cambio de aula y el catálogo.
     */
    #[Route('', name: 'space_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::ESPACIOS);

        // Se pasan la fecha y el tramo que traiga la petición: quien llegue con un enlace guardado a una hora
        // concreta tiene que aterrizar en ESA hora, no en la de ahora. Los nombres son los que lee
        // {@see GuardiaDate::fromRequest()} —`date`, no `fecha`—, que son además los que usaba esta pantalla.
        return $this->redirectToRoute('guardia_rooms', array_filter([
            'date' => $request->query->get('date'),
            'slot' => $request->query->get('slot'),
        ], static fn ($v): bool => null !== $v && '' !== $v));
    }

    /**
     * "Mis cambios de aula": the room changes in force that concern the person looking.
     *
     * Open to any signed-in user and scoped to themselves — like "mis guardias", and for the same
     * reason: it is where the notice about a change lands, so gating it behind the Espacios area would
     * send the affected teacher to a 403.
     */
    #[Route('/mis-cambios', name: 'space_mine', methods: ['GET'])]
    public function mine(
        #[CurrentUser] User $user,
        AcademicYearRepository $years,
        ScheduleEntryRepository $schedule,
        SpacePlanAssignmentRepository $assignments,
    ): Response {
        $today = new \DateTimeImmutable('today');
        $year = $years->findBySchoolYear(SchoolYear::current($today));

        // From today on: what already happened is of no use to somebody asking where their next class is.
        $horizon = null !== $year ? $year->getYearEnd() : $today->modify('+1 year');

        return $this->render('space/mine.html.twig', [
            'lines' => $assignments->inForceForTeacher($user, $today, $horizon),
            'slotTimes' => $schedule->slotTimes($year),
        ]);
    }
}
