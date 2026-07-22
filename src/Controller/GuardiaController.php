<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GuardiaCover;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Guardia\GuardiaScheduler;
use App\Repository\GuardiaCoverRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The daily "Parte de guardias": for a chosen day and period it shows the absences to cover, the
 * guardia teacher assigned to each (filled automatically and overridable), and the pool of guardia
 * teachers on call that period with their accumulated load.
 *
 * Assignment is automatic (the equitable {@see GuardiaScheduler}) with manual override, per the
 * centre's decision. The covers are {@see \App\Contract\Auditable}, so every change is trailed
 * automatically. Open to any authenticated user for now; restricting it to a guardia-coordinator
 * role is a follow-up once that role exists.
 */
#[Route('/guardias')]
final class GuardiaController extends AbstractController
{
    /**
     * Shows the parte for a date and period, plus the on-call pool. Date and period come from the
     * query string (today and the first period of the day by default).
     */
    #[Route('', name: 'guardia_index', methods: ['GET'])]
    public function index(Request $request, ScheduleEntryRepository $schedule, GuardiaCoverRepository $covers, UserRepository $users): Response
    {
        $date = $this->dateFromRequest($request);
        $slots = $schedule->distinctSlots();
        $slotIndex = $this->slotFromRequest($request, $slots);
        $weekday = Weekday::from((int) $date->format('N'));

        $pool = $schedule->dutyPoolAt($weekday, $slotIndex);
        $slotLoad = $covers->confirmedLoadBySlot($slotIndex);
        $absentIds = $covers->absentTeacherIdsAt($date, $slotIndex);

        return $this->render('guardia/index.html.twig', [
            'date' => $date,
            'weekday' => $weekday,
            'slots' => $slots,
            'slotIndex' => $slotIndex,
            'covers' => $covers->findForParte($date, $slotIndex),
            'pool' => $pool,
            'slotLoad' => $slotLoad,
            'absentIds' => $absentIds,
            'allTeachers' => $users->findBy([], ['fullName' => 'ASC']),
        ]);
    }

    /**
     * Registers an absence (a new parte line) and immediately runs the equitable assignment for its
     * period. The uncovered group and room are snapshotted from the absent teacher's timetable.
     */
    #[Route('/ausencia', name: 'guardia_add_absence', methods: ['POST'])]
    public function addAbsence(Request $request, UserRepository $users, ScheduleEntryRepository $schedule, GuardiaCoverRepository $covers, GuardiaScheduler $scheduler, EntityManagerInterface $em): Response
    {
        $this->assertCsrf($request, 'guardia_add_absence');

        $date = $this->dateFromRequest($request);
        $slotIndex = (int) $request->request->get('slot');
        $teacher = $users->find((int) $request->request->get('absent_teacher'));
        if (!$teacher instanceof User) {
            $this->addFlash('error', 'Profesor no encontrado.');

            return $this->backToParte($date, $slotIndex);
        }

        if (null !== $covers->findOneBy(['absentTeacher' => $teacher, 'date' => $date, 'slotIndex' => $slotIndex])) {
            $this->addFlash('error', sprintf('%s ya está en el parte de esa hora.', $teacher->getFullName()));

            return $this->backToParte($date, $slotIndex);
        }

        $lective = $schedule->lectiveAt($teacher, Weekday::from((int) $date->format('N')), $slotIndex);
        $cover = (new GuardiaCover())
            ->setDate($date)
            ->setSlotIndex($slotIndex)
            ->setAbsentTeacher($teacher)
            ->setGroupName($lective?->getGroupName())
            ->setRoomName($lective?->getRoomName())
            ->setTaskNote((string) $request->request->get('task_note'));
        $em->persist($cover);
        $em->flush();

        $scheduler->autoAssign($date, $slotIndex);
        $this->addFlash('success', sprintf('Ausencia de %s registrada.', $teacher->getFullName()));

        return $this->backToParte($date, $slotIndex);
    }

    /**
     * Re-runs the equitable assignment for a period, filling any still-unassigned covers.
     */
    #[Route('/asignar', name: 'guardia_auto_assign', methods: ['POST'])]
    public function autoAssign(Request $request, GuardiaScheduler $scheduler): Response
    {
        $this->assertCsrf($request, 'guardia_auto_assign');
        $date = $this->dateFromRequest($request);
        $slotIndex = (int) $request->request->get('slot');

        $assigned = $scheduler->autoAssign($date, $slotIndex);
        $this->addFlash('success', 0 === $assigned ? 'No había guardias pendientes de asignar.' : sprintf('%d guardia(s) asignada(s).', $assigned));

        return $this->backToParte($date, $slotIndex);
    }

    /**
     * Overrides the guardia assigned to a cover (the coordinator's manual choice). An empty value
     * clears the assignment.
     */
    #[Route('/{id}/reasignar', name: 'guardia_reassign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reassign(GuardiaCover $cover, Request $request, UserRepository $users, EntityManagerInterface $em): Response
    {
        $this->assertCsrf($request, 'guardia_reassign'.$cover->getId());

        $teacherId = $request->request->get('guardia');
        $cover->setAssignedGuardia('' !== (string) $teacherId ? $users->find((int) $teacherId) : null);
        $em->flush();
        $this->addFlash('success', 'Guardia reasignada.');

        return $this->backToParte($cover->getDate(), $cover->getSlotIndex());
    }

    /**
     * Toggles a cover's confirmation. A confirmed cover is what counts towards the equitable balance.
     */
    #[Route('/{id}/confirmar', name: 'guardia_confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function confirm(GuardiaCover $cover, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertCsrf($request, 'guardia_confirm'.$cover->getId());

        $cover->setConfirmed(!$cover->isConfirmed());
        $em->flush();

        return $this->backToParte($cover->getDate(), $cover->getSlotIndex());
    }

    /**
     * Deletes a parte line.
     */
    #[Route('/{id}/borrar', name: 'guardia_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(GuardiaCover $cover, Request $request, EntityManagerInterface $em): Response
    {
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
