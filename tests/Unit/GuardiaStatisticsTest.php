<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Guardia\GuardiaStatistics;
use PHPUnit\Framework\TestCase;

/**
 * The pure statistics behind the coordinator's dashboard: equity (mean/median/Gini), monthly split
 * and the weekday × period heatmap.
 */
final class GuardiaStatisticsTest extends TestCase
{
    private GuardiaStatistics $stats;

    protected function setUp(): void
    {
        $this->stats = new GuardiaStatistics();
    }

    public function testEquityOfAnEmptySetIsNeutral(): void
    {
        $e = $this->stats->equity([]);

        self::assertSame(0, $e['count']);
        self::assertSame('—', $e['label']);
        self::assertSame(0.0, $e['gini']);
    }

    public function testEquityOfAPerfectlyEvenSplitHasZeroGini(): void
    {
        $e = $this->stats->equity([2, 2, 2]);

        self::assertSame(2.0, $e['mean']);
        self::assertSame(2.0, $e['median']);
        self::assertSame(0, $e['spread']);
        self::assertSame(0.0, $e['gini']);
        self::assertSame('Muy equilibrado', $e['label']);
    }

    public function testEquityComputesMeanMedianAndGini(): void
    {
        // 1,2,3,4 → mean 2.5, median 2.5, spread 3, Gini 0.25.
        $e = $this->stats->equity([3, 1, 4, 2]);

        self::assertSame(4, $e['count']);
        self::assertSame(2.5, $e['mean']);
        self::assertSame(2.5, $e['median']);
        self::assertSame(1, $e['min']);
        self::assertSame(4, $e['max']);
        self::assertSame(3, $e['spread']);
        self::assertSame(0.25, $e['gini']);
        self::assertSame('Equilibrado', $e['label']);
    }

    public function testByMonthBucketsAndClassifies(): void
    {
        $rows = [
            $this->row('2025-11-10', 0, assigned: true, incident: false),   // covered
            $this->row('2025-11-12', 1, assigned: false, incident: false),  // unassigned
            $this->row('2025-12-01', 0, assigned: true, incident: true),    // incident
        ];

        $months = $this->stats->byMonth($rows);

        self::assertCount(2, $months);
        // Oldest first.
        self::assertSame('2025-11', $months[0]['key']);
        self::assertSame('nov 25', $months[0]['label']);
        self::assertSame(2, $months[0]['absences']);
        self::assertSame(1, $months[0]['covered']);
        self::assertSame(1, $months[0]['unassigned']);
        self::assertSame(0, $months[0]['incidents']);
        self::assertSame('2025-12', $months[1]['key']);
        self::assertSame(1, $months[1]['incidents']);
        self::assertSame(0, $months[1]['covered']);
    }

    public function testHeatmapGridTotalsAndIgnoresWeekend(): void
    {
        $rows = [
            $this->row('2025-11-10', 0), // Monday
            $this->row('2025-11-10', 0), // Monday, same cell
            $this->row('2025-11-11', 1), // Tuesday
            $this->row('2025-11-15', 0), // Saturday → ignored
        ];

        $h = $this->stats->heatmap($rows);

        self::assertSame([1, 2, 3, 4, 5], $h['weekdays']);
        self::assertSame([0, 1], $h['slots']);
        self::assertSame(2, $h['grid'][1][0]);
        self::assertSame(1, $h['grid'][2][1]);
        self::assertSame(2, $h['max']);
        self::assertSame(2, $h['weekdayTotals'][1]);
        self::assertSame(1, $h['weekdayTotals'][2]);
        self::assertSame(2, $h['slotTotals'][0]);
        self::assertArrayNotHasKey(6, $h['weekdayTotals']);
    }

    /**
     * Builds one analytics row.
     *
     * @return array{date: \DateTimeImmutable, slot: int, assigned: bool, incident: bool}
     */
    private function row(string $date, int $slot, bool $assigned = true, bool $incident = false): array
    {
        return ['date' => new \DateTimeImmutable($date), 'slot' => $slot, 'assigned' => $assigned, 'incident' => $incident];
    }
}
