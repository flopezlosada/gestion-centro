<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Guardia\GuardiaQuotaBalance;
use PHPUnit\Framework\TestCase;

/**
 * The balance behind the quota screen: what the week needs against what the equipo directivo pledged,
 * and the three states a teacher can be in (with a quota, exempt, or not decided yet).
 */
final class GuardiaQuotaBalanceTest extends TestCase
{
    private GuardiaQuotaBalance $balance;

    protected function setUp(): void
    {
        $this->balance = new GuardiaQuotaBalance();
    }

    public function testTheWeekNeedsFivePeopleInEveryTeachingPeriod(): void
    {
        $summary = $this->balance->summarise(6, []);

        // The centre's real frame: six teaching periods, five days, three guardias plus two de apoyo.
        self::assertSame(5, $summary['perSlot']);
        self::assertSame(5, $summary['days']);
        self::assertSame(150, $summary['needed']);
    }

    public function testTypingTwoForEveryoneLeavesTheRotaShort(): void
    {
        // The case that made this screen worth building: sixty teachers on two guardias each is thirty
        // placements short of the week, and nobody notices until the rota comes out wrong in October.
        $summary = $this->balance->summarise(6, $this->quotas(60, 2));

        self::assertSame(120, $summary['pledged']);
        self::assertSame(30, $summary['gap']);
        self::assertSame(0, $summary['surplus']);
    }

    public function testAnExactFitReportsNeitherGapNorSurplus(): void
    {
        $summary = $this->balance->summarise(1, $this->quotas(25, 1));

        self::assertSame(25, $summary['needed']);
        self::assertSame(25, $summary['pledged']);
        self::assertSame(0, $summary['gap']);
        self::assertSame(0, $summary['surplus']);
    }

    public function testSurplusIsReportedWhenMoreIsPledgedThanNeeded(): void
    {
        $summary = $this->balance->summarise(1, $this->quotas(30, 2));

        self::assertSame(25, $summary['needed']);
        self::assertSame(60, $summary['pledged']);
        self::assertSame(0, $summary['gap']);
        self::assertSame(35, $summary['surplus']);
    }

    public function testNobodyIsExemptUntilSomebodySaysSo(): void
    {
        // The bug this guards: on a fresh course nothing has been typed, and reading those zeros as
        // exemptions made the screen announce that the entire claustro was exempt.
        $summary = $this->balance->summarise(6, [
            ['lective' => 0, 'break' => 0, 'configured' => false],
            ['lective' => 0, 'break' => 0, 'configured' => false],
        ]);

        self::assertSame(2, $summary['pending']);
        self::assertSame(0, $summary['exempt']);
        self::assertSame(0, $summary['staffed']);
        self::assertSame(2, $summary['available']);
    }

    public function testAZeroThatWasTypedInIsAnExemption(): void
    {
        $summary = $this->balance->summarise(6, [
            ['lective' => 0, 'break' => 0, 'configured' => true],
            ['lective' => 2, 'break' => 0, 'configured' => true],
            ['lective' => 0, 'break' => 0, 'configured' => false],
        ]);

        self::assertSame(1, $summary['exempt']);
        self::assertSame(1, $summary['staffed']);
        self::assertSame(1, $summary['pending']);
        self::assertSame(3, $summary['teachers']);
        // Available = everyone who is not exempt, the pending one included: they may still be given one.
        self::assertSame(2, $summary['available']);
    }

    public function testASingleRecreoIsNotAnExemption(): void
    {
        // Somebody down to one recreo is still carrying part of the rota. Counting them out would
        // overstate how few people are left, which is the figure this screen exists to get right.
        $summary = $this->balance->summarise(6, [['lective' => 0, 'break' => 1, 'configured' => true]]);

        self::assertSame(0, $summary['exempt']);
        self::assertSame(1, $summary['staffed']);
    }

    public function testFairShareIsSpreadOverEveryoneWhoIsNotExempt(): void
    {
        // Two exempt out of four, so the 25 placements fall on the other two — including the one nobody
        // has decided about yet, which is what makes the figure useful on the very first visit.
        $summary = $this->balance->summarise(1, [
            ['lective' => 1, 'break' => 0, 'configured' => true],
            ['lective' => 0, 'break' => 0, 'configured' => false],
            ['lective' => 0, 'break' => 0, 'configured' => true],
            ['lective' => 0, 'break' => 0, 'configured' => true],
        ]);

        self::assertSame(12.5, $summary['fairShare']);
    }

    public function testEverybodyExemptDoesNotDivideByZero(): void
    {
        $summary = $this->balance->summarise(6, [
            ['lective' => 0, 'break' => 0, 'configured' => true],
            ['lective' => 0, 'break' => 0, 'configured' => true],
        ]);

        self::assertSame(0, $summary['available']);
        self::assertSame(0.0, $summary['fairShare']);
        self::assertSame(150, $summary['gap']);
    }

    public function testACourseWithNoImportedFrameNeedsNothingYet(): void
    {
        // Zero teaching periods means the frame is missing, not that the week is empty. The figure is
        // honest here and the screen says so with a warning rather than pretending the rota is covered.
        $summary = $this->balance->summarise(0, [
            ['lective' => 2, 'break' => 1, 'configured' => true],
            ['lective' => 2, 'break' => 1, 'configured' => true],
        ]);

        self::assertSame(0, $summary['needed']);
        self::assertSame(0, $summary['gap']);
        self::assertSame(4, $summary['pledged']);
        self::assertSame(2, $summary['breakPledged']);
    }

    public function testRecreoQuotasAreTalliedSeparatelyFromTeachingOnes(): void
    {
        $summary = $this->balance->summarise(6, [
            ['lective' => 2, 'break' => 1, 'configured' => true],
            ['lective' => 3, 'break' => 2, 'configured' => true],
        ]);

        self::assertSame(5, $summary['pledged']);
        self::assertSame(3, $summary['breakPledged']);
    }

    /**
     * @return list<array{lective: int, break: int, configured: bool}> that many teachers on that quota
     */
    private function quotas(int $howMany, int $lective): array
    {
        return array_fill(0, $howMany, ['lective' => $lective, 'break' => 0, 'configured' => true]);
    }
}
