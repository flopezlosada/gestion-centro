<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Enum\Area;
use App\Enum\ScheduleActivityKind;
use App\Enum\ScheduleEntrySource;
use App\Enum\Weekday;
use App\Guardia\RotaPlanner;
use App\Guardia\RotaProposal;
use App\Repository\AcademicYearRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\TimeSlotRepository;
use App\Security\Voter\AreaVoter;
use App\Util\SchoolYear;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The weekly guardia rota: what it looks like now, what the engine would propose, and the one button
 * that turns the second into the first.
 *
 * **Nothing is stored between proposing and approving.** The engine is deterministic — same timetable
 * and same quotas, same rota — so a draft can be thrown away and rebuilt identically instead of being
 * parked in a table, a session or a temp file that then needs a lifetime, a sweep and a story for what
 * happens when two people have one open. Approving simply asks for the proposal again and writes it.
 *
 * The narrow window that buys: if somebody changes a quota between the two clicks, what gets written is
 * the rota for the quotas as they are at that moment, not the one on screen. It is the correct rota
 * either way, and the flash says how many cells were written.
 *
 * Retouching is not done here. A grid of 150 cells with a dropdown in each would be 150 synthetic
 * listboxes and a screen nobody can read; the per-teacher editor at {@see GuardiaScheduleController}
 * already does that job, and what it changes is kept as fixed by the next proposal.
 */
#[Route('/guardias/cuadrante')]
final class GuardiaRotaController extends AbstractController
{
    /**
     * The rota of a course as a period × weekday grid, with a proposal shown instead when one has just
     * been asked for.
     */
    #[Route('', name: 'guardia_rota_index', methods: ['GET'])]
    public function index(
        Request $request,
        AcademicYearRepository $years,
        ScheduleEntryRepository $schedule,
        TimeSlotRepository $timeSlots,
        RotaPlanner $planner,
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);

        $curso = (string) ($request->query->get('curso') ?: SchoolYear::current(new \DateTimeImmutable('today')));
        $year = $years->findBySchoolYear($curso);
        $draft = $request->query->getBoolean('propuesta');

        $proposal = null;
        $grid = null;
        $slots = [];
        if ($year instanceof AcademicYear) {
            $slots = $planner->lectiveSlots($year);
            $proposal = $draft ? $planner->propose($year) : null;
            $grid = null !== $proposal
                ? $this->gridFromProposal($proposal, $planner->candidates($year), $this->sourcesByCell($year, $schedule))
                : $this->gridFromTimetable($year, $schedule);
        }

        return $this->render('guardia/rota.html.twig', [
            'courses' => $years->findAllOrdered(),
            'curso' => $curso,
            'year' => $year,
            'slots' => $slots,
            'slotTimes' => $year instanceof AcademicYear ? $this->timesBySlot($timeSlots, $year) : [],
            'weekdays' => Weekday::schoolWeek(),
            'grid' => $grid,
            'proposal' => $proposal,
            'summary' => $proposal?->summary(),
            'gaps' => $proposal?->gapsByReason() ?? [],
            'isDraft' => null !== $proposal,
        ]);
    }

    /**
     * Approves the proposal: asks the engine for it again and writes it into the timetable.
     *
     * A proposal that places nobody is not published ({@see RotaPlanner::publish()} refuses it, because
     * the write is a replace and an empty one would wipe the rota). Saying so out loud matters as much as
     * refusing it: a silent no-op with a green flash reading "0 guardias publicadas" is how somebody
     * concludes the program is broken.
     */
    #[Route('/aprobar', name: 'guardia_rota_approve', methods: ['POST'])]
    public function approve(Request $request, AcademicYearRepository $years, RotaPlanner $planner): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);

        if (!$this->isCsrfTokenValid('guardia_rota_approve', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $curso = (string) $request->request->get('curso', '');
        $year = $years->findBySchoolYear($curso);
        if (!$year instanceof AcademicYear) {
            $this->addFlash('error', 'Ese curso no existe.');

            return $this->redirectToRoute('guardia_rota_index');
        }

        $proposal = $planner->propose($year);
        $written = $planner->publish($year, $proposal->placements);

        if (0 === $written) {
            $this->addFlash('error', 'No se ha publicado nada: la propuesta no coloca a nadie. Revisa los cupos antes de publicar — sin cupo, el programa no tiene a quién poner de guardia.');

            return $this->redirectToRoute('guardia_rota_index', ['curso' => $curso, 'propuesta' => 1]);
        }

        $this->addFlash('success', sprintf(
            'Cuadrante publicado: %d guardias en el horario del curso %s.',
            $written,
            $curso,
        ));

        return $this->redirectToRoute('guardia_rota_index', ['curso' => $curso]);
    }

    /**
     * The marco horario keyed by period index, so the grid can label a row with its real hours. Empty
     * when the course's frame has not been imported: the grid then names periods by their ordinal
     * rather than inventing a time.
     *
     * @param TimeSlotRepository $timeSlots the frame
     * @param AcademicYear       $year      the course
     *
     * @return array<int, \App\Entity\TimeSlot> period index → period
     */
    private function timesBySlot(TimeSlotRepository $timeSlots, AcademicYear $year): array
    {
        $bySlot = [];
        foreach ($timeSlots->findLectiveByYear($year) as $slot) {
            $bySlot[$slot->getSlotIndex()] = $slot;
        }

        return $bySlot;
    }

    /**
     * The rota currently in the timetable, laid out as period → weekday → the people on duty.
     *
     * Every cell exists even when empty, so the template can index it without existence checks (Twig
     * raises under strict_variables on a missing key).
     *
     * @param AcademicYear            $year     the course
     * @param ScheduleEntryRepository $schedule the timetable
     *
     * @return array<int, array<int, list<array{name: string, kind: string, source: string}>>> period → weekday → people
     */
    private function gridFromTimetable(AcademicYear $year, ScheduleEntryRepository $schedule): array
    {
        $grid = [];
        foreach ($schedule->findDutyCells($year) as $cell) {
            $grid[$cell->getSlotIndex()][$cell->getWeekday()->value][] = [
                'name' => $cell->getTeacher()->getFullName(),
                'kind' => $cell->getKind()->value,
                'source' => $cell->getSource()->value,
            ];
        }

        return $grid;
    }

    /**
     * Where each duty cell already in the timetable came from, keyed by period, weekday and teacher.
     *
     * The proposal knows a place is fixed but not who fixed it, and the difference is worth showing: a
     * guardia that arrived in the Peñalara export is the centre's official rota, while a hand-marked one
     * is somebody's decision — and they are retouched in different places.
     *
     * @param AcademicYear            $year     the course
     * @param ScheduleEntryRepository $schedule the timetable
     *
     * @return array<string, string> "slot:weekday:teacherId" → source
     */
    private function sourcesByCell(AcademicYear $year, ScheduleEntryRepository $schedule): array
    {
        $sources = [];
        foreach ($schedule->findDutyCells($year) as $cell) {
            $key = $cell->getSlotIndex().':'.$cell->getWeekday()->value.':'.$cell->getTeacher()->getId();
            $sources[$key] = $cell->getSource()->value;
        }

        return $sources;
    }

    /**
     * The same layout, built from a proposal that has not been written anywhere.
     *
     * @param RotaProposal                     $proposal   the draft
     * @param list<\App\Guardia\RotaCandidate> $candidates the candidates, for their names
     * @param array<string, string>            $sources    where the already-existing cells came from
     *
     * @return array<int, array<int, list<array{name: string, kind: string, source: string}>>> period → weekday → people
     */
    private function gridFromProposal(RotaProposal $proposal, array $candidates, array $sources): array
    {
        $names = [];
        foreach ($candidates as $candidate) {
            $names[$candidate->teacherId] = $candidate->name;
        }

        $grid = [];
        foreach ($proposal->placements as $place) {
            $key = $place['slot'].':'.$place['weekday'].':'.$place['teacherId'];
            $grid[$place['slot']][$place['weekday']][] = [
                'name' => $names[$place['teacherId']] ?? '—',
                'kind' => $place['kind'],
                // A fixed place already exists in the timetable; the grid says where it came from,
                // because it is the one thing on the draft that approving will not change.
                'source' => $place['fixed']
                    ? ($sources[$key] ?? ScheduleEntrySource::MANUAL->value)
                    : ScheduleEntrySource::ENGINE->value,
            ];
        }

        return $grid;
    }
}
