<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Guardia\GuardiaScheduler;
use App\Repository\AcademicYearRepository;
use App\Repository\GuardiaCoverRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Service\GuardiaAssignmentNotifier;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The daily "Parte de guardias": for a chosen day and period it shows the absences to cover, the
 * guardia teacher assigned to each (filled automatically and overridable), and the pool of guardia
 * teachers on call that period with their accumulated load.
 *
 * Assignment is automatic (the equitable {@see GuardiaScheduler}) with manual override, per the
 * centre's decision. The covers are {@see \App\Contract\Auditable}, so every change is trailed
 * automatically.
 *
 * This is the guardia-coordinator surface, gated by the {@see Area::GUARDIAS} matrix: viewing (parte,
 * history, stats) needs READ, every mutation needs WRITE (ROLE_ADMIN bypasses). The one exception is
 * {@see mine()}: a teacher's own "mis guardias" is open to any authenticated user and shows only their
 * own covers.
 */
#[Route('/guardias')]
final class GuardiaController extends AbstractController
{
    /**
     * Shows the parte for a date and period, plus the on-call pool. Date and period come from the
     * query string (today and the first period of the day by default).
     */
    #[Route('', name: 'guardia_index', methods: ['GET'])]
    public function index(Request $request, ScheduleEntryRepository $schedule, GuardiaCoverRepository $covers, UserRepository $users, AcademicYearRepository $years): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::GUARDIAS);

        $date = $this->dateFromRequest($request);
        $schoolYear = SchoolYear::current($date);
        $year = $years->findBySchoolYear($schoolYear);
        $weekday = Weekday::from((int) $date->format('N'));

        // Slots and the guardia pool come from the timetable of the course this date falls into; with
        // no course imported for it there is nothing to show but the empty state.
        $slots = null !== $year ? $schedule->distinctSlots($year) : [];
        $slotIndex = $this->slotFromRequest($request, $slots);
        $pool = null !== $year ? $schedule->dutyPoolAt($year, $weekday, $slotIndex) : [];

        return $this->render('guardia/index.html.twig', [
            'date' => $date,
            'weekday' => $weekday,
            'schoolYear' => $schoolYear,
            'slots' => $slots,
            'slotIndex' => $slotIndex,
            'covers' => $covers->findForParte($date, $slotIndex),
            'pool' => $pool,
            'slotLoad' => $covers->loadBySlot($slotIndex),
            'absentIds' => $covers->absentTeacherIdsAt($date, $slotIndex),
            'allTeachers' => $users->findBy([], ['fullName' => 'ASC']),
        ]);
    }

    /**
     * The teacher's own "mis guardias de hoy": the guardias assigned to them today, with the period
     * time, group, room, absent teacher and the task the absent teacher left. Shows only their own.
     */
    #[Route('/mias', name: 'guardia_mine', methods: ['GET'])]
    public function mine(#[CurrentUser] User $user, GuardiaCoverRepository $covers, ScheduleEntryRepository $schedule, AcademicYearRepository $years): Response
    {
        $today = new \DateTimeImmutable('today');
        $year = $years->findBySchoolYear(SchoolYear::current($today));

        // Personal counter + the staff average as context ("have I done my share?").
        $ranking = $covers->coveredTotalsByTeacher();
        $staffAverage = [] !== $ranking
            ? array_sum(array_map(static fn (array $r): int => $r['total'], $ranking)) / \count($ranking)
            : 0.0;

        return $this->render('guardia/mine.html.twig', [
            'date' => $today,
            'covers' => $covers->findAssignedTo($user, $today),
            'slotTimes' => $this->slotTimes($schedule, $year),
            'myCovered' => $covers->countCoveredForTeacher($user),
            'staffAverage' => round($staffAverage, 1),
        ]);
    }

    /**
     * The guardia log with optional filters (date range, group, guardia teacher, absent teacher) — the
     * trace of "who covered which group when". Read access to the guardia area is enough to view it.
     */
    #[Route('/historico', name: 'guardia_history', methods: ['GET'])]
    public function history(Request $request, GuardiaCoverRepository $covers, UserRepository $users): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::GUARDIAS);

        $from = $this->optionalDate((string) $request->query->get('from'));
        $to = $this->optionalDate((string) $request->query->get('to'));
        $group = trim((string) $request->query->get('group'));
        $assigned = $this->optionalUser($users, $request->query->get('assigned'));
        $absent = $this->optionalUser($users, $request->query->get('absent'));

        return $this->render('guardia/history.html.twig', [
            'covers' => $covers->history($from, $to, '' !== $group ? $group : null, $assigned, $absent),
            'groups' => $covers->distinctGroups(),
            'allTeachers' => $users->findBy([], ['fullName' => 'ASC']),
            'filters' => [
                'from' => $from?->format('Y-m-d') ?? '',
                'to' => $to?->format('Y-m-d') ?? '',
                'group' => $group,
                'assigned' => $assigned?->getId(),
                'absent' => $absent?->getId(),
            ],
        ]);
    }

    /**
     * Guardia statistics for the coordinator. Four lenses: coverage health (registered vs covered vs
     * incidents vs still unassigned), fairness of the split per teacher (with the staff average so
     * imbalance is visible), absences by period (where cover is needed most) and who is absent most.
     * Read access to the area is enough.
     */
    #[Route('/estadisticas', name: 'guardia_stats', methods: ['GET'])]
    public function stats(GuardiaCoverRepository $covers, ScheduleEntryRepository $schedule, AcademicYearRepository $years): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::GUARDIAS);

        $ranking = $covers->coveredTotalsByTeacher();
        $teacherCount = \count($ranking);
        $coveredTotal = array_sum(array_map(static fn (array $r): int => $r['total'], $ranking));

        $summary = $covers->coverageSummary();
        $coverageRate = $summary['absences'] > 0 ? (int) round($summary['covered'] * 100 / $summary['absences']) : 0;

        // Period labels from the current course's marco horario (a stat may span the course, but the
        // period grid is stable within a year); fall back to the ordinal when a slot has no label.
        $year = $years->findBySchoolYear(SchoolYear::current(new \DateTimeImmutable('today')));

        return $this->render('guardia/stats.html.twig', [
            'ranking' => $ranking,
            'coveredTotal' => $coveredTotal,
            'teacherCount' => $teacherCount,
            'max' => $ranking[0]['total'] ?? 0,
            'average' => $teacherCount > 0 ? round($coveredTotal / $teacherCount, 1) : 0.0,
            'summary' => $summary,
            'coverageRate' => $coverageRate,
            'absencesBySlot' => $covers->absencesBySlot(),
            'slotTimes' => $this->slotTimes($schedule, $year),
            'absentRanking' => $covers->absencesByTeacher(10),
        ]);
    }

    /**
     * Registers an absence (a new parte line) and immediately runs the equitable assignment for its
     * period. The uncovered group and room are snapshotted from the absent teacher's timetable.
     */
    #[Route('/ausencia', name: 'guardia_add_absence', methods: ['POST'])]
    public function addAbsence(Request $request, UserRepository $users, ScheduleEntryRepository $schedule, GuardiaCoverRepository $covers, GuardiaScheduler $scheduler, AcademicYearRepository $years, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_add_absence');

        $date = $this->dateFromRequest($request);
        $slotIndex = (int) $request->request->get('slot');
        $year = $years->findBySchoolYear(SchoolYear::current($date));
        if (!$year instanceof AcademicYear) {
            $this->addFlash('error', sprintf('No hay horario importado para el curso %s. Impórtalo antes de registrar ausencias.', SchoolYear::current($date)));

            return $this->backToParte($date, $slotIndex);
        }

        $teacher = $users->find((int) $request->request->get('absent_teacher'));
        if (!$teacher instanceof User) {
            $this->addFlash('error', 'Profesor no encontrado.');

            return $this->backToParte($date, $slotIndex);
        }

        if (null !== $covers->findOneBy(['absentTeacher' => $teacher, 'date' => $date, 'slotIndex' => $slotIndex])) {
            $this->addFlash('error', sprintf('%s ya está en el parte de esa hora.', $teacher->getFullName()));

            return $this->backToParte($date, $slotIndex);
        }

        $lective = $schedule->lectiveAt($year, $teacher, Weekday::from((int) $date->format('N')), $slotIndex);
        $cover = (new GuardiaCover())
            ->setDate($date)
            ->setSlotIndex($slotIndex)
            ->setAbsentTeacher($teacher)
            ->setGroupName($lective?->getGroupName())
            ->setRoomName($lective?->getRoomName())
            ->setTaskNote((string) $request->request->get('task_note'));
        $em->persist($cover);
        $em->flush();

        $scheduler->autoAssign($year, $date, $slotIndex);
        $this->addFlash('success', sprintf('Ausencia de %s registrada.', $teacher->getFullName()));

        return $this->backToParte($date, $slotIndex);
    }

    /**
     * Re-runs the equitable assignment for a period, filling any still-unassigned covers.
     */
    #[Route('/asignar', name: 'guardia_auto_assign', methods: ['POST'])]
    public function autoAssign(Request $request, GuardiaScheduler $scheduler, AcademicYearRepository $years): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_auto_assign');
        $date = $this->dateFromRequest($request);
        $slotIndex = (int) $request->request->get('slot');

        $year = $years->findBySchoolYear(SchoolYear::current($date));
        if (!$year instanceof AcademicYear) {
            $this->addFlash('error', sprintf('No hay horario importado para el curso %s.', SchoolYear::current($date)));

            return $this->backToParte($date, $slotIndex);
        }

        $assigned = $scheduler->autoAssign($year, $date, $slotIndex);
        $this->addFlash('success', 0 === $assigned ? 'No había guardias pendientes de asignar.' : sprintf('%d guardia(s) asignada(s).', $assigned));

        return $this->backToParte($date, $slotIndex);
    }

    /**
     * Overrides the guardia assigned to a cover (the coordinator's manual choice). An empty value
     * clears the assignment.
     */
    #[Route('/{id}/reasignar', name: 'guardia_reassign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reassign(GuardiaCover $cover, Request $request, UserRepository $users, EntityManagerInterface $em, GuardiaAssignmentNotifier $notifier): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_reassign'.$cover->getId());

        $teacherId = $request->request->get('guardia');
        $previous = $cover->getAssignedGuardia();
        $cover->setAssignedGuardia('' !== (string) $teacherId ? $users->find((int) $teacherId) : null);
        $em->flush();

        // Avisa solo cuando el titular de la guardia cambia (reseleccionar al mismo no genera aviso).
        if ($cover->getAssignedGuardia() !== $previous) {
            $notifier->notifyAssigned($cover);
        }
        $this->addFlash('success', 'Guardia reasignada.');

        return $this->backToParte($cover->getDate(), $cover->getSlotIndex());
    }

    /**
     * Toggles a cover's incident flag. An assigned cover counts as done by default; flagging an
     * incident ("no se cubrió / el profe tampoco vino") takes it out of the equitable balance.
     */
    #[Route('/{id}/incidencia', name: 'guardia_incident', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markIncident(GuardiaCover $cover, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_incident'.$cover->getId());

        $cover->setNotCovered(!$cover->isNotCovered());
        $em->flush();

        return $this->backToParte($cover->getDate(), $cover->getSlotIndex());
    }

    /**
     * Deletes a parte line.
     */
    #[Route('/{id}/borrar', name: 'guardia_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(GuardiaCover $cover, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_delete'.$cover->getId());

        $date = $cover->getDate();
        $slotIndex = $cover->getSlotIndex();
        $em->remove($cover);
        $em->flush();
        $this->addFlash('success', 'Línea del parte eliminada.');

        return $this->backToParte($date, $slotIndex);
    }

    /**
     * Reads the requested date from the query/post ("Y-m-d"), falling back to today on absence or a
     * bad value.
     *
     * @param Request $request the current request
     *
     * @return \DateTimeImmutable the date to show (time set to midnight)
     */
    private function dateFromRequest(Request $request): \DateTimeImmutable
    {
        $raw = (string) ($request->query->get('date') ?? $request->request->get('date'));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return false !== $date ? $date : new \DateTimeImmutable('today');
    }

    /**
     * Reads the requested period index, defaulting to the day's first period when absent or unknown.
     *
     * @param Request                                                                          $request the current request
     * @param list<array{index: int, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> $slots   the available periods
     *
     * @return int the period index to show
     */
    private function slotFromRequest(Request $request, array $slots): int
    {
        if ($request->query->has('slot')) {
            return (int) $request->query->get('slot');
        }

        return $slots[0]['index'] ?? 0;
    }

    /**
     * The given course's periods keyed by their index, so a view holding only a {@code slotIndex}
     * (e.g. a cover) can print the period's start/end time without another query per row. Empty when
     * no course (hence no timetable) applies.
     *
     * @param ScheduleEntryRepository $schedule the timetable repository
     * @param AcademicYear|null       $year     the course whose periods to read, or null
     *
     * @return array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> times by slot index
     */
    private function slotTimes(ScheduleEntryRepository $schedule, ?AcademicYear $year): array
    {
        if (null === $year) {
            return [];
        }

        $times = [];
        foreach ($schedule->distinctSlots($year) as $slot) {
            $times[$slot['index']] = ['startsAt' => $slot['startsAt'], 'endsAt' => $slot['endsAt']];
        }

        return $times;
    }

    /**
     * Parses an optional "Y-m-d" date from a filter field, returning null when empty or malformed.
     *
     * @param string $raw the raw field value
     *
     * @return \DateTimeImmutable|null the parsed date, or null
     */
    private function optionalDate(string $raw): ?\DateTimeImmutable
    {
        if ('' === $raw) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return false !== $date ? $date : null;
    }

    /**
     * Resolves an optional user id from a filter field, returning null when empty or not found.
     *
     * @param UserRepository $users the user repository
     * @param mixed          $raw   the raw field value (id or empty)
     *
     * @return User|null the user, or null
     */
    private function optionalUser(UserRepository $users, mixed $raw): ?User
    {
        $id = (int) $raw;

        return $id > 0 ? $users->find($id) : null;
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
