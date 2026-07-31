<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\BreakPeriod;
use App\Guardia\BreakRotaCandidate;
use App\Guardia\BreakRotaProposer;
use PHPUnit\Framework\TestCase;

/**
 * The break rota engine: the rules it cannot break, the fairness it optimises for, and the halves it
 * reports when the week's demand simply does not pair up.
 */
final class BreakRotaProposerTest extends TestCase
{
    private BreakRotaProposer $proposer;

    protected function setUp(): void
    {
        $this->proposer = new BreakRotaProposer();
    }

    public function testAQuotaOfZeroNeverAppears(): void
    {
        // The exemption rule, same as the teaching rota: orientación, PSC and the equipo directivo.
        $exempt = new BreakRotaCandidate(1, 'Jefatura', 0);
        $ana = new BreakRotaCandidate(2, 'Ana', 2);

        $proposal = $this->proposer->propose($this->week([[1, BreakPeriod::FIRST, 10, 3]]), [$exempt, $ana]);

        foreach ($proposal->places as $place) {
            self::assertNotSame(1, $place['teacherId'], 'an exempt teacher was placed');
        }
        self::assertSame([2], array_column($proposal->byTeacher, 'teacherId'), 'nor are they in the load report');
    }

    public function testNobodyIsPlacedTwiceAtTheSameRecreoOfTheSameDay(): void
    {
        // Two zones needing somebody at Monday's long recreo: it has to be two different people.
        $places = $this->week([[1, BreakPeriod::FIRST, 10, 3], [1, BreakPeriod::FIRST, 20, 1]]);
        $candidates = [new BreakRotaCandidate(1, 'Ana', 5), new BreakRotaCandidate(2, 'Luis', 5)];

        $proposal = $this->proposer->propose($places, $candidates);

        self::assertCount(2, $proposal->places);
        self::assertNotSame($proposal->places[0]['teacherId'], $proposal->places[1]['teacherId']);
    }

    public function testTheSamePersonMayWatchDifferentZonesAtEachRecreoOfADay(): void
    {
        // Flatly impossible under the old model, and something the centre asked for.
        $places = $this->week([[1, BreakPeriod::FIRST, 10, 3], [1, BreakPeriod::SECOND, 20, 1]]);

        $proposal = $this->proposer->propose($places, [new BreakRotaCandidate(1, 'Ana', 1)]);

        self::assertCount(2, $proposal->places);
        self::assertSame(1, $proposal->byTeacher[0]['guardias'], 'a long plus a short is one guardia');
        self::assertSame(0, $proposal->byTeacher[0]['halves']);
    }

    public function testNobodyGoesPastTheirQuota(): void
    {
        // A quota of one is one long place and one short one, no matter how many places the week holds.
        $places = $this->week([
            [1, BreakPeriod::FIRST, 10, 3], [2, BreakPeriod::FIRST, 10, 3], [3, BreakPeriod::FIRST, 10, 3],
            [1, BreakPeriod::SECOND, 10, 3], [2, BreakPeriod::SECOND, 10, 3],
        ]);

        $proposal = $this->proposer->propose($places, [new BreakRotaCandidate(1, 'Ana', 1)]);

        self::assertCount(2, $proposal->places, 'one long and one short, and no more');
        self::assertSame(3, $proposal->summary()['unfilled']);
    }

    public function testTheHeaviestZonesAreSpreadInsteadOfPilingOnOnePerson(): void
    {
        // Two heavy places and two light ones, two people on a quota each. Handing them out in demand
        // order would give both patios to whoever came first; heaviest-first with a load tiebreak does
        // not. This is the centre's "no todas las zonas cuestan igual" made to mean something.
        $places = $this->week([
            [1, BreakPeriod::FIRST, 10, 3], [2, BreakPeriod::FIRST, 10, 3],
            [1, BreakPeriod::SECOND, 20, 1], [2, BreakPeriod::SECOND, 20, 1],
        ]);
        $candidates = [new BreakRotaCandidate(1, 'Ana', 1), new BreakRotaCandidate(2, 'Luis', 1)];

        $proposal = $this->proposer->propose($places, $candidates);

        $loads = array_column($proposal->byTeacher, 'load');
        self::assertSame([4, 4], $loads, 'each carries one heavy zone and one light one');
    }

    public function testASurplusOfLongPlacesComesOutAsHalvesNotAsExtraGuardias(): void
    {
        // The centre's real shape: the patio dirigido is not watched at the short recreo, so the week
        // holds more long places than short ones. Three long and one short cannot be three guardias, and
        // pretending otherwise would overstate what everybody has done.
        $places = $this->week([
            [1, BreakPeriod::FIRST, 10, 2], [2, BreakPeriod::FIRST, 10, 2], [3, BreakPeriod::FIRST, 10, 2],
            [1, BreakPeriod::SECOND, 10, 2],
        ]);
        $candidates = [new BreakRotaCandidate(1, 'Ana', 3)];

        $proposal = $this->proposer->propose($places, $candidates);

        $summary = $proposal->summary();
        self::assertSame(4, $summary['placed']);
        self::assertSame(1, $summary['guardias'], 'only one long place found a short partner');
        self::assertSame(2, $summary['halves'], 'the other two are halves, and the report says so');
    }

    public function testGuardiasAreSpreadAcrossTheWeekRatherThanPiledOnOneDay(): void
    {
        // One person, four long places over four days: they should not all land on Monday — and cannot,
        // since two places at the same recreo of one day is already forbidden. What this pins down is
        // that the engine uses the days it has instead of leaving gaps.
        $places = $this->week([
            [1, BreakPeriod::FIRST, 10, 1], [2, BreakPeriod::FIRST, 10, 1],
            [3, BreakPeriod::FIRST, 10, 1], [4, BreakPeriod::FIRST, 10, 1],
        ]);

        $proposal = $this->proposer->propose($places, [new BreakRotaCandidate(1, 'Ana', 4)]);

        $days = array_column($proposal->places, 'weekday');
        self::assertSame($days, array_unique($days), 'four different days');
        self::assertCount(4, $proposal->places);
    }

    public function testPeopleAreMovedAroundTheZonesInsteadOfRepeatingOne(): void
    {
        // "Puede cambiar de actividad", in the centre's words. With two zones of the same weight and one
        // person on two long places, they should see both rather than the same one twice.
        $places = $this->week([[1, BreakPeriod::FIRST, 10, 2], [2, BreakPeriod::FIRST, 20, 2]]);

        $proposal = $this->proposer->propose($places, [new BreakRotaCandidate(1, 'Ana', 2)]);

        $zones = array_column($proposal->places, 'zoneId');
        sort($zones);
        self::assertSame([10, 20], $zones);
    }

    public function testHandPinnedPlacesAreKeptAndCountAgainstTheQuota(): void
    {
        // The patios dirigidos the equipo directivo organises by hand: the engine honours them and does
        // not then hand that person more than their quota.
        $places = $this->week([[1, BreakPeriod::FIRST, 10, 3], [2, BreakPeriod::FIRST, 10, 3]]);
        $fixed = [['weekday' => 1, 'period' => BreakPeriod::FIRST, 'zoneId' => 10, 'teacherId' => 1]];

        $proposal = $this->proposer->propose($places, [new BreakRotaCandidate(1, 'Ana', 1)], $fixed);

        $pinned = array_values(array_filter($proposal->places, static fn (array $p): bool => $p['fixed']));
        self::assertCount(1, $pinned);
        self::assertSame(1, $pinned[0]['teacherId']);
        // Quota of one at the long recreo, already spent by hand: the other place goes unfilled.
        self::assertCount(1, $proposal->places);
        self::assertSame(1, $proposal->summary()['unfilled']);
    }

    public function testAGapSaysWhetherQuotasRanOutOrNobodyWasLeftInThatCell(): void
    {
        // Two places at the same recreo but only one person: quotas are not the problem, bodies are.
        $places = $this->week([[1, BreakPeriod::FIRST, 10, 1], [1, BreakPeriod::FIRST, 20, 1]]);

        $proposal = $this->proposer->propose($places, [new BreakRotaCandidate(1, 'Ana', 5)]);

        self::assertSame([BreakRotaProposer::GAP_NOBODY_LEFT => 1], $proposal->gapsByReason());
    }

    public function testTheReportNamesWhoIsBelowTheirQuota(): void
    {
        $places = $this->week([[1, BreakPeriod::FIRST, 10, 1], [1, BreakPeriod::SECOND, 10, 1]]);

        $proposal = $this->proposer->propose($places, [new BreakRotaCandidate(1, 'Ana', 3)]);

        self::assertSame(1, $proposal->byTeacher[0]['guardias']);
        self::assertSame(3, $proposal->byTeacher[0]['quota']);
        self::assertSame(1, $proposal->summary()['shortOfQuota']);
    }

    public function testTheSameInputAlwaysGivesTheSameRota(): void
    {
        // Re-running after an edit must not shuffle everybody else's recreos.
        $places = $this->week([
            [1, BreakPeriod::FIRST, 10, 3], [2, BreakPeriod::FIRST, 20, 1],
            [1, BreakPeriod::SECOND, 10, 3], [3, BreakPeriod::SECOND, 20, 1],
        ]);
        $candidates = [
            new BreakRotaCandidate(1, 'Ana', 1),
            new BreakRotaCandidate(2, 'Luis', 1),
            new BreakRotaCandidate(3, 'Marta', 1),
        ];

        $first = $this->proposer->propose($places, $candidates);
        $second = $this->proposer->propose($places, $candidates);

        self::assertSame($first->places, $second->places);
        self::assertSame($first->unfilled, $second->unfilled);
    }

    /**
     * Turns [weekday, period, zoneId, weight] rows into the shape the engine takes.
     *
     * @param list<array{0: int, 1: BreakPeriod, 2: int, 3: int}> $rows the places
     *
     * @return list<array{weekday: int, period: BreakPeriod, zoneId: int, weight: int}> the week's demand
     */
    private function week(array $rows): array
    {
        return array_map(
            static fn (array $r): array => ['weekday' => $r[0], 'period' => $r[1], 'zoneId' => $r[2], 'weight' => $r[3]],
            $rows,
        );
    }
}
