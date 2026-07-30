<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\User;
use App\Enum\GuardiaDutyBand;
use App\Guardia\GuardiaAssigner;
use App\Guardia\GuardiaCandidate;
use PHPUnit\Framework\TestCase;

/**
 * The equitable rule: fewest guardias at this period first, then fewest in total, then by name; bands
 * (rota → collaborators → hand-added support) open only while the groups to cover outnumber the people
 * gathered so far; and doubling somebody up is the very last resort.
 */
final class GuardiaAssignerTest extends TestCase
{
    private GuardiaAssigner $assigner;

    protected function setUp(): void
    {
        $this->assigner = new GuardiaAssigner();
    }

    public function testOrdersByPeriodLoadThenTotalThenName(): void
    {
        $candidates = [
            $this->guardia('Carlos', slotLoad: 2, totalLoad: 2),
            $this->guardia('Ana', slotLoad: 0, totalLoad: 9),   // fewest at this period wins despite high total
            $this->guardia('Bea', slotLoad: 1, totalLoad: 1),
            $this->guardia('Diego', slotLoad: 1, totalLoad: 1), // level with Bea -> name breaks the tie
        ];

        self::assertSame(['Ana', 'Bea', 'Diego', 'Carlos'], $this->names($this->assigner->prioritise(4, $candidates)));
    }

    public function testCollaboratorsAreDroppedWhenGuardiasCoverEveryGroup(): void
    {
        $candidates = [
            $this->guardia('Ana', 0, 0),
            $this->guardia('Bea', 0, 0),
            $this->collaborator('Convivencia', 0, 0),
        ];

        $order = $this->assigner->prioritise(2, $candidates);

        self::assertCount(2, $order, 'two guardias cover two groups; the collaborator is not offered');
        self::assertSame(['Ana', 'Bea'], $this->names($order));
    }

    public function testCollaboratorsJoinAfterGuardiasWhenAbsencesExceedGuardias(): void
    {
        $candidates = [
            $this->guardia('Ana', 0, 0),
            $this->collaborator('Convivencia', 0, 0),
            $this->guardia('Bea', 0, 0),
        ];

        $order = $this->assigner->prioritise(3, $candidates);

        self::assertSame(
            ['Ana', 'Bea', 'Convivencia'],
            $this->names($order),
            'collaborators only after every guardia, and only because 3 groups need covering',
        );
    }

    public function testEmptyPoolYieldsNoAssignments(): void
    {
        self::assertSame([], $this->assigner->prioritise(3, []));
        self::assertSame([], $this->assigner->sequence(3, []), 'nobody at all yields no picks, not a crash');
    }

    public function testSupportTeachersJoinOnlyAfterGuardiasAndCollaborators(): void
    {
        $candidates = [
            $this->support('Liberado', 0, 0),
            $this->collaborator('Convivencia', 0, 0),
            $this->guardia('Ana', 0, 0),
        ];

        self::assertSame(
            ['Ana'],
            $this->names($this->assigner->prioritise(1, $candidates)),
            'one group: the rota covers it, neither the collaborator nor the hand-added support is offered',
        );
        self::assertSame(
            ['Ana', 'Convivencia'],
            $this->names($this->assigner->prioritise(2, $candidates)),
            'two groups: the collaborator opens, support still not needed',
        );
        self::assertSame(
            ['Ana', 'Convivencia', 'Liberado'],
            $this->names($this->assigner->prioritise(3, $candidates)),
            'three groups: hand-added support is the last band to open',
        );
    }

    public function testNobodyIsDoubledUpWhileThereAreEnoughTeachers(): void
    {
        $candidates = [
            $this->guardia('Ana', 0, 0),
            $this->guardia('Bea', 0, 0),
            $this->doubling('Carlos', hereLoad: 1),
        ];

        self::assertSame(
            ['Ana', 'Bea'],
            $this->names($this->assigner->prioritise(2, $candidates)),
            'Carlos already covers a group this period, and two free teachers cover the two groups',
        );
    }

    public function testAlreadyBusyTeachersAreOfferedLastAndOnlyInDeficit(): void
    {
        $candidates = [
            $this->doubling('Carlos', hereLoad: 2),
            $this->doubling('Bea', hereLoad: 1),
            $this->guardia('Ana', 0, 0),
        ];

        self::assertSame(
            ['Ana', 'Bea', 'Carlos'],
            $this->names($this->assigner->prioritise(3, $candidates)),
            'the free teacher first, then whoever is least burdened here',
        );
    }

    public function testSequenceGivesEverybodyOneBeforeAnybodyTwo(): void
    {
        $candidates = [$this->guardia('Ana', 0, 0), $this->guardia('Bea', 0, 0)];

        self::assertSame(
            ['Ana', 'Bea', 'Ana', 'Bea', 'Ana'],
            $this->names($this->assigner->sequence(5, $candidates)),
            'five groups and two teachers: round robin, never a third before everybody has a second',
        );
    }

    public function testSequenceMatchesPrioritiseWhenThereIsNoDeficit(): void
    {
        $candidates = [$this->guardia('Ana', 0, 0), $this->guardia('Bea', 0, 0), $this->collaborator('Convivencia', 0, 0)];

        self::assertSame(
            ['Ana', 'Bea'],
            $this->names($this->assigner->sequence(2, $candidates)),
            'enough teachers: one pick each, no repetitions and no collaborator',
        );
    }

    /**
     * Builds a guardia candidate with the given balance.
     *
     * @param string $name      the teacher's full name
     * @param int    $slotLoad  guardias already done at this period
     * @param int    $totalLoad guardias already done in total
     *
     * @return GuardiaCandidate the candidate
     */
    private function guardia(string $name, int $slotLoad, int $totalLoad): GuardiaCandidate
    {
        return new GuardiaCandidate($this->user($name), GuardiaDutyBand::GUARDIA, $slotLoad, $totalLoad);
    }

    /**
     * Builds a collaborator candidate with the given balance.
     *
     * @param string $name      the teacher's full name
     * @param int    $slotLoad  guardias already done at this period
     * @param int    $totalLoad guardias already done in total
     *
     * @return GuardiaCandidate the candidate
     */
    private function collaborator(string $name, int $slotLoad, int $totalLoad): GuardiaCandidate
    {
        return new GuardiaCandidate($this->user($name), GuardiaDutyBand::COLLABORATOR, $slotLoad, $totalLoad);
    }

    /**
     * Builds a hand-added support candidate (a colleague freed from lessons for the day).
     *
     * @param string $name      the teacher's full name
     * @param int    $slotLoad  guardias already done at this period
     * @param int    $totalLoad guardias already done in total
     *
     * @return GuardiaCandidate the candidate
     */
    private function support(string $name, int $slotLoad, int $totalLoad): GuardiaCandidate
    {
        return new GuardiaCandidate($this->user($name), GuardiaDutyBand::SUPPORT, $slotLoad, $totalLoad);
    }

    /**
     * Builds a guardia candidate who is ALREADY covering groups at this date and period.
     *
     * @param string $name     the teacher's full name
     * @param int    $hereLoad groups they already cover at this date and period
     *
     * @return GuardiaCandidate the candidate
     */
    private function doubling(string $name, int $hereLoad): GuardiaCandidate
    {
        return new GuardiaCandidate($this->user($name), GuardiaDutyBand::GUARDIA, 0, 0, $hereLoad);
    }

    /**
     * The candidates' names, in order — what every assertion here compares.
     *
     * @param list<GuardiaCandidate> $candidates the ordered candidates
     *
     * @return list<string> their full names
     */
    private function names(array $candidates): array
    {
        return array_map(static fn (GuardiaCandidate $c): string => $c->teacher->getFullName(), $candidates);
    }

    /**
     * Builds a user with only the name set (all the comparator needs).
     *
     * @param string $name the full name
     *
     * @return User the user
     */
    private function user(string $name): User
    {
        return (new User())->setFullName($name);
    }
}
