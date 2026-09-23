<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Agenda\DayTimeline;
use App\Agenda\TimelineBlock;
use PHPUnit\Framework\TestCase;

/**
 * Positioning the day/week grid ({@see DayTimeline}): the window it shows, the hour marks down its side,
 * and the column layout that keeps overlapping blocks side by side instead of stacked unreadably.
 */
final class DayTimelineTest extends TestCase
{
    private DayTimeline $timeline;
    private \DateTimeImmutable $day;

    protected function setUp(): void
    {
        $this->timeline = new DayTimeline();
        $this->day = new \DateTimeImmutable('2026-09-23');
    }

    private function block(int $h1, int $m1, int $h2, int $m2, string $title = 'x'): TimelineBlock
    {
        return new TimelineBlock('class', $this->day->setTime($h1, $m1), $this->day->setTime($h2, $m2), $title, null, '#', 'cat-color--blue');
    }

    public function testEmptyDayGetsTheSchoolDayDefaultWindow(): void
    {
        [$start, $end] = $this->timeline->windowFor([], $this->day);

        self::assertSame('08:00', $start->format('H:i'));
        self::assertSame('21:00', $end->format('H:i'));
    }

    public function testWindowExpandsToFitAnEarlyOrLateBlockRoundedToTheHour(): void
    {
        [$start, $end] = $this->timeline->windowFor([$this->block(6, 40, 7, 10), $this->block(21, 50, 22, 10)], $this->day);

        self::assertSame('06:00', $start->format('H:i'), 'redondea hacia abajo');
        self::assertSame('23:00', $end->format('H:i'), 'redondea hacia arriba, tope 23:00');
    }

    public function testHourMarksCoverEveryHourOfTheWindow(): void
    {
        [$start, $end] = $this->timeline->windowFor([], $this->day);
        $marks = $this->timeline->hourMarks($start, $end);

        self::assertCount(13, $marks);
        self::assertSame('08:00', $marks[0]['label']);
        self::assertSame(0.0, $marks[0]['top']);
        self::assertSame('20:00', $marks[array_key_last($marks)]['label']);
    }

    public function testABlockNotOverlappingAnythingFillsTheFullWidth(): void
    {
        [$start, $end] = $this->timeline->windowFor([], $this->day);
        $positions = $this->timeline->layout([$this->block(9, 0, 10, 0)], $start, $end);

        self::assertSame(0.0, $positions[0]['left']);
        self::assertSame(100.0, $positions[0]['width']);
    }

    /** Tres clases que se solapan comparten fila en tres columnas iguales, cada una en la suya. */
    public function testOverlappingBlocksSplitIntoColumns(): void
    {
        [$start, $end] = $this->timeline->windowFor([], $this->day);
        $blocks = [
            $this->block(9, 0, 10, 0, 'A'),
            $this->block(9, 30, 10, 30, 'B'),
            $this->block(9, 45, 10, 15, 'C'),
        ];

        $byTitle = [];
        foreach ($this->timeline->layout($blocks, $start, $end) as $pos) {
            $byTitle[$pos['block']->title] = $pos;
        }

        self::assertSame(100.0 / 3, $byTitle['A']['width']);
        $lefts = array_unique(array_column($byTitle, 'left'));
        self::assertCount(3, $lefts, 'cada una en su propia columna');
    }

    /** Dos clusters separados en el tiempo se calculan cada uno con sus propias columnas. */
    public function testNonOverlappingClustersAreLaidOutIndependently(): void
    {
        [$start, $end] = $this->timeline->windowFor([], $this->day);
        $blocks = [
            $this->block(9, 0, 10, 0, 'A'),
            $this->block(9, 30, 10, 0, 'B'), // overlaps A: cluster 1, 2 columns
            $this->block(11, 0, 12, 0, 'D'), // separate cluster: alone, full width
        ];

        $byTitle = [];
        foreach ($this->timeline->layout($blocks, $start, $end) as $pos) {
            $byTitle[$pos['block']->title] = $pos;
        }

        self::assertSame(100.0, $byTitle['D']['width']);
        self::assertSame(50.0, $byTitle['A']['width']);
    }

    public function testAVeryShortBlockIsFlooredToAMinimumHeight(): void
    {
        [$start, $end] = $this->timeline->windowFor([], $this->day);
        $positions = $this->timeline->layout([$this->block(9, 0, 9, 5)], $start, $end);

        // 5 minutos reales en una ventana de 13h serían un hilo invisible; el piso los hace legibles.
        self::assertGreaterThan((5 / (13 * 60)) * 100, $positions[0]['height']);
    }

    public function testPercentInWindowIsNullOutsideTheWindow(): void
    {
        [$start, $end] = $this->timeline->windowFor([], $this->day);

        self::assertNull($this->timeline->percentInWindow($this->day->setTime(6, 0), $start, $end));
        self::assertNotNull($this->timeline->percentInWindow($this->day->setTime(12, 0), $start, $end));
    }
}
