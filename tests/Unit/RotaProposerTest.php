<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\ScheduleActivityKind;
use App\Guardia\RotaCandidate;
use App\Guardia\RotaDemand;
use App\Guardia\RotaProposer;
use PHPUnit\Framework\TestCase;

/**
 * The rota engine: the hard rules it can never break, the preferences it applies in order, and the
 * report it gives back when the week cannot be filled.
 */
final class RotaProposerTest extends TestCase
{
    private RotaProposer $proposer;

    protected function setUp(): void
    {
        $this->proposer = new RotaProposer();
    }

    public function testNobodyIsPlacedInAPeriodTheyTeach(): void
    {
        // Ana teaches Monday first period; the only other candidate does not.
        $ana = new RotaCandidate(1, 'Ana', 5, [1 => [0]]);
        $luis = new RotaCandidate(2, 'Luis', 5, []);

        $proposal = $this->proposer->propose([0], [$ana, $luis]);

        foreach ($proposal->placements as $place) {
            if (1 === $place['teacherId']) {
                self::assertNotSame([1, 0], [$place['weekday'], $place['slot']], 'Ana was placed in a period she teaches');
            }
        }
    }

    public function testNobodyIsPlacedTwiceInTheSamePeriod(): void
    {
        $candidates = [new RotaCandidate(1, 'Ana', 10, []), new RotaCandidate(2, 'Luis', 10, [])];

        $proposal = $this->proposer->propose([0], $candidates);

        $seen = [];
        foreach ($proposal->placements as $place) {
            $key = $place['weekday'].':'.$place['slot'].':'.$place['teacherId'];
            self::assertArrayNotHasKey($key, $seen, 'the same teacher was placed twice in one period');
            $seen[$key] = true;
        }
    }

    public function testNobodyGoesOverTheirQuota(): void
    {
        // Two people, quota 2 each, against a week that wants 25 placements.
        $candidates = [new RotaCandidate(1, 'Ana', 2, []), new RotaCandidate(2, 'Luis', 2, [])];

        $proposal = $this->proposer->propose([0], $candidates);

        $perTeacher = [];
        foreach ($proposal->placements as $place) {
            $perTeacher[$place['teacherId']] = ($perTeacher[$place['teacherId']] ?? 0) + 1;
        }
        self::assertSame([1 => 2, 2 => 2], $perTeacher);
        self::assertCount(4, $proposal->placements);
    }

    public function testAQuotaOfZeroNeverAppears(): void
    {
        // The exemption rule: orientación, PSC and the equipo directivo are a quota of zero.
        $exempt = new RotaCandidate(1, 'Jefatura', 0, []);
        $ana = new RotaCandidate(2, 'Ana', 3, []);

        $proposal = $this->proposer->propose([0], [$exempt, $ana]);

        foreach ($proposal->placements as $place) {
            self::assertNotSame(1, $place['teacherId'], 'an exempt teacher was placed');
        }
        // Nor are they listed in the load report: they are not part of this rota at all.
        self::assertSame([2], array_column($proposal->byTeacher, 'teacherId'));
    }

    public function testAGapBetweenOwnClassesIsPreferredToOneAtTheEdgeOfTheDay(): void
    {
        // Both are free in the period being filled, but Ana teaches either side of it — she is in the
        // building anyway — while Luis would be coming in for the guardia alone.
        $ana = new RotaCandidate(1, 'Ana', 1, [1 => [0, 2]]);
        $luis = new RotaCandidate(2, 'Luis', 1, []);

        $proposal = $this->proposer->propose([1], [$ana, $luis]);

        $monday = array_values(array_filter($proposal->placements, static fn (array $p): bool => 1 === $p['weekday']));
        self::assertSame(1, $monday[0]['teacherId'], 'the mid-morning gap should have been used first');
    }

    public function testAShortageFallsOnTheStandbyPlacesAndNeverOnTheGuardias(): void
    {
        // The finding that changed the algorithm. One period a day is 25 places: 15 guardias and 10
        // standby. With exactly 15 of quota to go round, every period must still get its three
        // guardias — filling period by period instead spent the quota on the first days and left the
        // last ones bare.
        $candidates = [];
        for ($i = 1; $i <= 15; ++$i) {
            $candidates[] = new RotaCandidate($i, 'Docente '.$i, 1, []);
        }

        $proposal = $this->proposer->propose([0], $candidates);

        $guardiasPerCell = [];
        foreach ($proposal->placements as $place) {
            if (ScheduleActivityKind::GUARDIA->value === $place['kind']) {
                $guardiasPerCell[$place['weekday']] = ($guardiasPerCell[$place['weekday']] ?? 0) + 1;
            }
        }
        self::assertSame([3, 3, 3, 3, 3], array_values($guardiasPerCell), 'some period was left short of guardias');
        self::assertSame(
            [ScheduleActivityKind::COLLABORATOR->value => 10],
            array_count_values(array_column($proposal->unfilled, 'kind')),
            'the shortage should fall entirely on the standby places',
        );
    }

    public function testGuardiasAreSpreadAcrossTheWeekRatherThanPiledOnOneDay(): void
    {
        // Somebody with a quota of five and a completely free week should not do all five on Monday.
        $ana = new RotaCandidate(1, 'Ana', 5, []);

        $proposal = $this->proposer->propose([0], [$ana]);

        $perDay = [];
        foreach ($proposal->placements as $place) {
            $perDay[$place['weekday']] = ($perDay[$place['weekday']] ?? 0) + 1;
        }
        self::assertSame(1, max($perDay), 'the week piled up on a single day');
        self::assertCount(5, $perDay);
    }

    public function testHandPinnedPlacesAreKeptAndCountAgainstTheQuota(): void
    {
        $ana = new RotaCandidate(1, 'Ana', 2, []);
        $fixed = [['weekday' => 3, 'slot' => 0, 'teacherId' => 1, 'kind' => ScheduleActivityKind::GUARDIA]];

        $proposal = $this->proposer->propose([0], [$ana], $fixed);

        $pinned = array_values(array_filter($proposal->placements, static fn (array $p): bool => $p['fixed']));
        self::assertCount(1, $pinned);
        self::assertSame([3, 0, 1], [$pinned[0]['weekday'], $pinned[0]['slot'], $pinned[0]['teacherId']]);
        // Quota of two, one of them already spent by hand: only one more may be added.
        self::assertCount(2, $proposal->placements);
        self::assertSame(2, $proposal->byTeacher[0]['assigned']);
    }

    public function testAPinnedPlaceReducesWhatItsPeriodStillNeeds(): void
    {
        $candidates = [];
        for ($i = 1; $i <= 20; ++$i) {
            $candidates[] = new RotaCandidate($i, 'Docente '.$i, 10, []);
        }
        $fixed = [['weekday' => 1, 'slot' => 0, 'teacherId' => 1, 'kind' => ScheduleActivityKind::GUARDIA]];

        $proposal = $this->proposer->propose([0], $candidates, $fixed);

        $monday = array_filter($proposal->placements, static fn (array $p): bool => 1 === $p['weekday']);
        // Still five places in that period, not six: the pinned one filled a guardia slot.
        self::assertCount(RotaDemand::perSlot(), $monday);
    }

    public function testAGapSaysWhetherNobodyWasFreeOrEverybodyWasOutOfQuota(): void
    {
        // Ana is the only candidate and she teaches Monday first period. Monday's five places are then
        // "nobody free" outright; Tuesday takes her single guardia and its remaining four are "nobody
        // free" too — she is already in that period and cannot be put in it twice, which no quota
        // change would fix. The rest of the week is quota: she is free there and simply has none left.
        $ana = new RotaCandidate(1, 'Ana', 1, [1 => [0]]);

        $proposal = $this->proposer->propose([0], [$ana]);
        $reasons = $proposal->gapsByReason();

        self::assertSame(9, $reasons[RotaProposer::GAP_NOBODY_FREE], 'Monday (5) plus the rest of Tuesday (4)');
        self::assertSame(15, $reasons[RotaProposer::GAP_QUOTA_EXHAUSTED], 'Wednesday, Thursday and Friday');
        // 25 places in the week, one of them filled.
        self::assertSame(RotaDemand::perSlot() * 5 - 1, \count($proposal->unfilled));
    }

    public function testTheSameInputAlwaysGivesTheSameRota(): void
    {
        // Re-running after an edit must not shuffle the rest of the week under somebody's feet.
        $candidates = [
            new RotaCandidate(1, 'Ana', 3, [1 => [0]]),
            new RotaCandidate(2, 'Luis', 3, [2 => [1]]),
            new RotaCandidate(3, 'Marta', 3, []),
        ];

        $first = $this->proposer->propose([0, 1], $candidates);
        $second = $this->proposer->propose([0, 1], $candidates);

        self::assertSame($first->placements, $second->placements);
        self::assertSame($first->unfilled, $second->unfilled);
    }

    public function testTheReportNamesWhoIsLeftBelowTheirQuota(): void
    {
        // Luis teaches every period of every day, so his quota cannot be spent anywhere.
        $busyAllWeek = [1 => [0], 2 => [0], 3 => [0], 4 => [0], 5 => [0]];
        $candidates = [new RotaCandidate(1, 'Ana', 2, []), new RotaCandidate(2, 'Luis', 4, $busyAllWeek)];

        $proposal = $this->proposer->propose([0], $candidates);
        $summary = $proposal->summary();

        self::assertSame(1, $summary['belowQuota']);
        self::assertSame(4, $summary['unusedQuota']);
        // Heaviest shortfall first, so the report opens on the quota going spare.
        self::assertSame('Luis', $proposal->byTeacher[0]['name']);
        self::assertSame(0, $proposal->byTeacher[0]['assigned']);
    }

    public function testPlacementsComeBackInReadingOrderNotInFillingOrder(): void
    {
        // The engine fills hardest-period-first; that is an implementation detail nobody should see.
        $candidates = [];
        for ($i = 1; $i <= 30; ++$i) {
            $candidates[] = new RotaCandidate($i, 'Docente '.$i, 10, [1 => [1]]);
        }

        $proposal = $this->proposer->propose([0, 1], $candidates);

        $order = array_map(static fn (array $p): array => [$p['weekday'], $p['slot']], $proposal->placements);
        $sorted = $order;
        sort($sorted);
        self::assertSame($sorted, $order);
    }
}
