<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Entity\Task;

/**
 * Turns a set of tasks into the figures the panel shows against the single task lifecycle
 * (Pendiente → Entregada → Finalizada, con Cancelada aparte): cuántas finalizadas / entregadas /
 * pendientes / canceladas, cuántas vencidas, el porcentaje de avance, las que requieren atención
 * (abiertas y vencidas) y el desglose por departamento.
 *
 * Pure by design: it derives everything from the tasks handed to it — already scoped to what the viewer
 * may see ({@see \App\Service\TaskVisibility}) — so a director gets the whole centre and a plain teacher
 * gets only their own, with no extra query. Dates are compared as "Y-m-d" strings, so it is
 * timezone-proof (same idiom as {@see \App\Agenda\PersonalAgenda}).
 */
final class CentreDashboard
{
    /** Finalizada: terminal, validada por un superior. El "avance" cuenta esto. */
    private const string FINALIZED = 'validated';

    /** Entregada: hecha por el responsable, a la espera de validación. */
    private const string SUBMITTED = 'submitted';

    /** Cancelada: cierre alternativo; no cuenta como abierta ni como avance. */
    private const string CANCELLED = 'cancelled';

    /**
     * Summarises the tasks for the dashboard.
     *
     * @param Task[]             $tasks the tasks in scope (already filtered by visibility)
     * @param \DateTimeImmutable $today the reference day for the overdue test
     *
     * @return array{
     *   total: int, finalized: int, submitted: int, pending: int, cancelled: int, overdue: int, pctFinalized: int,
     *   attention: list<Task>,
     *   byDepartment: list<array{name: string, total: int, finalized: int, overdue: int, pct: int}>
     * } the dashboard figures
     */
    public function overview(array $tasks, \DateTimeImmutable $today): array
    {
        $todayStr = $today->format('Y-m-d');
        $finalized = $submitted = $pending = $cancelled = $overdue = 0;
        $attention = [];
        /** @var array<string, array{name: string, total: int, finalized: int, overdue: int}> $byDept */
        $byDept = [];

        foreach ($tasks as $task) {
            $status = $task->getStatus();
            $isFinalized = self::FINALIZED === $status;
            // Abierta = ni finalizada ni cancelada (pendiente o entregada): solo lo abierto puede vencer.
            $isOpen = !$isFinalized && self::CANCELLED !== $status;
            $isOverdue = $isOpen && $task->getDueDate()->format('Y-m-d') < $todayStr;

            match ($status) {
                self::FINALIZED => ++$finalized,
                self::SUBMITTED => ++$submitted,
                self::CANCELLED => ++$cancelled,
                default => ++$pending,
            };

            if ($isOverdue) {
                ++$overdue;
                $attention[] = $task;
            }

            $deptName = $task->getUnit()?->getName() ?? 'Sin departamento';
            $byDept[$deptName] ??= ['name' => $deptName, 'total' => 0, 'finalized' => 0, 'overdue' => 0];
            ++$byDept[$deptName]['total'];
            if ($isFinalized) {
                ++$byDept[$deptName]['finalized'];
            }
            if ($isOverdue) {
                ++$byDept[$deptName]['overdue'];
            }
        }

        // Most urgent first: earliest deadline (the most overdue) at the top.
        usort($attention, static fn (Task $a, Task $b): int => $a->getDueDate() <=> $b->getDueDate());

        $byDepartment = array_map(
            static fn (array $d): array => [...$d, 'pct' => $d['total'] > 0 ? (int) round($d['finalized'] / $d['total'] * 100) : 0],
            array_values($byDept),
        );
        // Most behind first (lowest % finalized), then by name for a stable order.
        usort($byDepartment, static fn (array $a, array $b): int => [$a['pct'], $a['name']] <=> [$b['pct'], $b['name']]);

        $total = \count($tasks);

        return [
            'total' => $total,
            'finalized' => $finalized,
            'submitted' => $submitted,
            'pending' => $pending,
            'cancelled' => $cancelled,
            'overdue' => $overdue,
            'pctFinalized' => $total > 0 ? (int) round($finalized / $total * 100) : 0,
            'attention' => $attention,
            'byDepartment' => $byDepartment,
        ];
    }
}
