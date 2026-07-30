<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakDutyGap;
use App\Entity\BreakZone;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\BreakPeriodCoverage;
use App\Enum\Weekday;
use App\Guardia\BreakDutyRoster;
use App\Repository\AcademicYearRepository;
use App\Repository\BreakDutyAssignmentRepository;
use App\Repository\BreakDutyGapRepository;
use App\Repository\BreakZoneRepository;
use App\Repository\TimeSlotRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Util\SchoolYear;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The break duty rota ("cuadrante de recreo"): who watches which zone during each day's recreos, fixed
 * for the whole course.
 *
 * It is a separate surface from the daily parte because it is a different kind of thing. The parte
 * reshuffles cover for absent colleagues every morning; this is drawn up once in September and holds
 * all year, and when the teacher on it is away the recreo is NOT re-covered — the equipo directivo is
 * alerted to find a volunteer, and the day is recorded as a {@see BreakDutyGap}. Both rules come
 * straight from the centre.
 *
 * Gated by the {@see Area::GUARDIAS} matrix like the rest of the module: READ to look at the rota and
 * the gaps, WRITE to change either (ROLE_ADMIN bypasses).
 */
#[Route('/guardias/recreo')]
final class BreakDutyController extends AbstractController
{
    /**
     * The rota of a course as a weekday × zone grid, with the recreos it covers, where it is short of
     * people, and the weighted equity reading of how the turns are spread.
     */
    #[Route('', name: 'break_duty_index', methods: ['GET'])]
    public function index(Request $request, AcademicYearRepository $years, BreakDutyRoster $roster, BreakZoneRepository $zones, UserRepository $users, BreakDutyGapRepository $gaps): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::GUARDIAS);

        $today = new \DateTimeImmutable('today');
        $curso = (string) ($request->query->get('curso') ?: SchoolYear::current($today));
        $year = $years->findBySchoolYear($curso);
        $overview = $year instanceof AcademicYear ? $roster->overview($year) : null;

        return $this->render('guardia/break_duty_index.html.twig', [
            'courses' => $years->findAllOrdered(),
            'curso' => $curso,
            'year' => $year,
            'grid' => $overview['grid'] ?? null,
            'equity' => $overview['equity'] ?? null,
            'zones' => $zones->findActiveOrdered(),
            'teachers' => $users->findBy(['active' => true], ['fullName' => 'ASC']),
            'weekdays' => Weekday::schoolWeek(),
            'coverages' => BreakPeriodCoverage::cases(),
            'todayGaps' => $gaps->findByDate($today),
            'canManage' => $this->isGranted(AreaVoter::WRITE, Area::GUARDIAS),
        ]);
    }

    /**
     * Adds one duty to the rota: a teacher, a weekday, a zone and which recreos it spans.
     *
     * The clash the unique key guards (one duty per teacher and weekday) is caught rather than
     * pre-checked: two people editing the September rota at once is exactly the situation, and a check
     * before the insert would still lose that race.
     */
    #[Route('/asignar', name: 'break_duty_assign', methods: ['POST'])]
    public function assign(Request $request, AcademicYearRepository $years, UserRepository $users, BreakZoneRepository $zones, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'break_duty_assign');

        $curso = (string) $request->request->get('curso');
        $year = $years->findBySchoolYear($curso);
        $teacher = $users->find((int) $request->request->get('teacher'));
        $zone = $zones->find((int) $request->request->get('zone'));
        $weekday = Weekday::tryFrom((int) $request->request->get('weekday'));
        $periods = BreakPeriodCoverage::tryFrom((string) $request->request->get('periods'));

        if (!$year instanceof AcademicYear || !$teacher instanceof User || !$zone instanceof BreakZone || null === $weekday || null === $periods) {
            $this->addFlash('error', 'Elige curso, profesor, día, zona y tramos.');

            return $this->redirectToRoute('break_duty_index', ['curso' => $curso]);
        }

        $duty = (new BreakDutyAssignment())
            ->setAcademicYear($year)
            ->setTeacher($teacher)
            ->setWeekday($weekday)
            ->setZone($zone)
            ->setPeriods($periods);

        try {
            $em->persist($duty);
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', sprintf('%s ya tiene una guardia de recreo el %s. Edítala o cámbiala de día: una persona no puede estar en dos zonas a la vez.', $teacher->getFullName(), mb_strtolower($weekday->label())));

            return $this->redirectToRoute('break_duty_index', ['curso' => $curso]);
        }

        $this->addFlash('success', sprintf('%s vigila %s los %s (%s).', $teacher->getFullName(), $zone->getName(), mb_strtolower($weekday->label()), mb_strtolower($periods->label())));

        return $this->redirectToRoute('break_duty_index', ['curso' => $curso]);
    }

    /**
     * Removes one duty from the rota. Its recorded gaps go with it (they are events of that duty), which
     * is why this is a deliberate gesture and not a side effect of editing anything else.
     */
    #[Route('/{id}/quitar', name: 'break_duty_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remove(BreakDutyAssignment $duty, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'break_duty_remove'.$duty->getId());

        $curso = $duty->getAcademicYear()->getSchoolYear();
        $name = $duty->getTeacher()->getFullName();
        $zone = $duty->getZone()->getName();

        $em->remove($duty);
        $em->flush();

        $this->addFlash('success', sprintf('Quitada la guardia de recreo de %s en %s.', $name, $zone));

        return $this->redirectToRoute('break_duty_index', ['curso' => $curso]);
    }

    /**
     * The recreos left unwatched: the ones still to resolve first (today onwards), then the history. This
     * is where an alert about an unwatched zone lands, and where whoever volunteers is written down.
     */
    #[Route('/huecos', name: 'break_duty_gap_index', methods: ['GET'])]
    public function gaps(Request $request, AcademicYearRepository $years, BreakDutyGapRepository $gaps, UserRepository $users): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::GUARDIAS);

        $today = new \DateTimeImmutable('today');
        $curso = (string) ($request->query->get('curso') ?: SchoolYear::current($today));
        $year = $years->findBySchoolYear($curso);
        $all = $year instanceof AcademicYear ? $gaps->findByYear($year) : [];

        // Split around today rather than filtered in the query: the two lists are read differently (one is
        // a to-do, the other a record) and the rows are few — a course's worth of unwatched recreos.
        $pending = array_values(array_filter($all, static fn (BreakDutyGap $g): bool => $g->getDate() >= $today));
        $past = array_values(array_filter($all, static fn (BreakDutyGap $g): bool => $g->getDate() < $today));

        return $this->render('guardia/break_duty_gaps.html.twig', [
            'courses' => $years->findAllOrdered(),
            'curso' => $curso,
            'year' => $year,
            'pending' => array_reverse($pending), // soonest first: what to solve today comes first
            'past' => $past,
            'summary' => $year instanceof AcademicYear ? $gaps->summary($year) : ['total' => 0, 'covered' => 0],
            'teachers' => $users->findBy(['active' => true], ['fullName' => 'ASC']),
            'canManage' => $this->isGranted(AreaVoter::WRITE, Area::GUARDIAS),
        ]);
    }

    /**
     * Records who volunteered to watch a zone whose duty holder was away — or clears it again — plus an
     * optional note. The gap itself is never deleted: that a recreo went uncovered is what the centre
     * wants to be able to look back on.
     */
    #[Route('/huecos/{id}', name: 'break_duty_gap_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateGap(BreakDutyGap $gap, Request $request, UserRepository $users, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'break_duty_gap'.$gap->getId());

        $volunteerId = (int) $request->request->get('volunteer');
        $volunteer = $volunteerId > 0 ? $users->find($volunteerId) : null;

        $gap->setVolunteer($volunteer instanceof User ? $volunteer : null);
        $gap->setNote((string) $request->request->get('note'));
        $em->flush();

        $this->addFlash('success', $gap->isCovered()
            ? sprintf('%s cubre el recreo de %s del %s.', $gap->getVolunteer()?->getFullName() ?? '', $gap->getAssignment()->getZone()->getName(), $gap->getDate()->format('d/m/Y'))
            : sprintf('El recreo de %s del %s queda sin voluntario.', $gap->getAssignment()->getZone()->getName(), $gap->getDate()->format('d/m/Y')));

        return $this->redirectToRoute('break_duty_gap_index', ['curso' => $gap->getAssignment()->getAcademicYear()->getSchoolYear()]);
    }

    /**
     * The zones and their weights: the management screen behind the rota. Editable from the app because
     * the centre adds zones ("patio dirigido" is new this course) and tunes how demanding each one is —
     * neither should need a deploy.
     */
    #[Route('/zonas', name: 'break_zone_index', methods: ['GET'])]
    public function zones(BreakZoneRepository $zones, BreakDutyAssignmentRepository $duties, TimeSlotRepository $timeSlots, AcademicYearRepository $years): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);

        $year = $years->findBySchoolYear(SchoolYear::current(new \DateTimeImmutable('today')));
        $all = $zones->findAllOrdered();

        // How many duties each zone holds: what makes archiving the honest gesture instead of deleting.
        $usage = [];
        foreach ($all as $zone) {
            $usage[(int) $zone->getId()] = $duties->countByZone($zone);
        }

        return $this->render('guardia/break_zone_index.html.twig', [
            'zones' => $all,
            'usage' => $usage,
            'breaks' => $timeSlots->findBreaksByYear($year instanceof AcademicYear ? $year : null),
            'minWeight' => BreakZone::MIN_WEIGHT,
            'maxWeight' => BreakZone::MAX_WEIGHT,
        ]);
    }

    /**
     * Creates or updates a zone. Archiving is part of the same form: a zone in use is never deleted, so
     * the rotas that already name it keep making sense.
     */
    #[Route('/zonas', name: 'break_zone_save', methods: ['POST'])]
    public function saveZone(Request $request, BreakZoneRepository $zones, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'break_zone_save');

        $id = (int) $request->request->get('id');
        $zone = $id > 0 ? $zones->find($id) : new BreakZone();
        if (!$zone instanceof BreakZone) {
            throw $this->createNotFoundException('Zona no encontrada.');
        }

        $name = trim((string) $request->request->get('name'));
        if ('' === $name) {
            $this->addFlash('error', 'Ponle nombre a la zona.');

            return $this->redirectToRoute('break_zone_index');
        }

        $isNew = null === $zone->getId();
        $zone->setName($name)
            ->setWeight(max(BreakZone::MIN_WEIGHT, min(BreakZone::MAX_WEIGHT, (int) $request->request->get('weight', 1))))
            ->setRequiredTeachers(max(1, (int) $request->request->get('required', 1)))
            ->setArchived($request->request->getBoolean('archived'));
        if ($isNew) {
            $zone->setSortOrder($zones->maxSortOrder() + 1);
        }

        try {
            $em->persist($zone);
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', sprintf('Ya existe una zona llamada «%s».', $name));

            return $this->redirectToRoute('break_zone_index');
        }

        $this->addFlash('success', $isNew ? sprintf('Zona «%s» añadida.', $zone->getName()) : sprintf('Zona «%s» actualizada.', $zone->getName()));

        return $this->redirectToRoute('break_zone_index');
    }

    /**
     * Rejects a POST whose CSRF token does not match, with the same 403 the rest of the module uses.
     *
     * @param Request $request the posted request
     * @param string  $id      the token id the form was rendered with
     */
    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }
}
