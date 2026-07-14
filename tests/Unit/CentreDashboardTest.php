<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dashboard\CentreDashboard;
use App\Entity\Department;
use App\Entity\Task;
use App\Enum\TaskType;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard aggregation is pure: given a set of tasks and a reference day it must partition them by
 * the single lifecycle (finalizada / entregada / pendiente / cancelada), count the overdue ones,
 * compute the completion percentage and the per-department breakdown, and surface the tasks that need
 * attention (open and overdue), most urgent first.
 */
final class CentreDashboardTest extends TestCase
{
    private const string YEAR = '2025-2026';

    private function task(string $title, string $status, string $due, ?Department $unit = null): Task
    {
        $task = new Task($title, self::YEAR, new \DateTimeImmutable($due), TaskType::SIMPLE);
        $task->setStatus($status)->setUnit($unit);

        return $task;
    }

    public function testPartitionsCountsAndPercentage(): void
    {
        $today = new \DateTimeImmutable('2026-03-01');
        $tasks = [
            $this->task('finalizada', 'validated', '2026-02-01'),
            $this->task('otra finalizada', 'validated', '2026-02-10'),
            $this->task('pendiente futura', 'pending', '2026-04-01'),
            $this->task('entregada futura', 'submitted', '2026-04-01'),
        ];

        $overview = (new CentreDashboard())->overview($tasks, $today);

        self::assertSame(4, $overview['total']);
        self::assertSame(2, $overview['finalized']);
        self::assertSame(1, $overview['submitted']);
        self::assertSame(1, $overview['pending']);
        self::assertSame(0, $overview['cancelled']);
        self::assertSame(0, $overview['overdue']);
        self::assertSame(50, $overview['pctFinalized']);
        self::assertSame([], $overview['attention']);
    }

    public function testAttentionCollectsOpenOverdueMostUrgentFirst(): void
    {
        $today = new \DateTimeImmutable('2026-03-01');
        $overdueOld = $this->task('vencida antigua', 'pending', '2026-01-15');
        $overdueRecent = $this->task('vencida reciente', 'submitted', '2026-02-20');
        $finalizedPast = $this->task('finalizada en fecha pasada', 'validated', '2026-01-01');
        $cancelledPast = $this->task('cancelada en fecha pasada', 'cancelled', '2026-01-05');

        $overview = (new CentreDashboard())->overview([$finalizedPast, $overdueRecent, $cancelledPast, $overdueOld], $today);

        // Overdue = abierta (pendiente o entregada) y con fecha pasada: las dos abiertas, no la
        // finalizada ni la cancelada.
        self::assertSame(2, $overview['overdue']);
        // Attention = las vencidas abiertas, por fecha más antigua primero.
        self::assertSame(
            ['vencida antigua', 'vencida reciente'],
            array_map(static fn (Task $t): string => $t->getTitle(), $overview['attention']),
        );
    }

    public function testPerDepartmentBreakdownMostBehindFirst(): void
    {
        $today = new \DateTimeImmutable('2026-03-01');
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $lang = (new Department())->setCode('lang')->setName('Lengua');
        $tasks = [
            $this->task('m1', 'validated', '2026-02-01', $maths),
            $this->task('m2', 'validated', '2026-02-02', $maths),
            $this->task('l1', 'validated', '2026-02-01', $lang),
            $this->task('l2', 'pending', '2026-01-01', $lang), // overdue
        ];

        $byDepartment = (new CentreDashboard())->overview($tasks, $today)['byDepartment'];

        // Lengua (50% finalizadas, 1 vencida) va más atrasada que Matemáticas (100%), así que va primera.
        self::assertSame('Lengua', $byDepartment[0]['name']);
        self::assertSame(50, $byDepartment[0]['pct']);
        self::assertSame(1, $byDepartment[0]['overdue']);
        self::assertSame('Matemáticas', $byDepartment[1]['name']);
        self::assertSame(100, $byDepartment[1]['pct']);
    }
}
