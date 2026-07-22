<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\User;
use App\Guardia\GuardiaAssigner;
use App\Guardia\GuardiaCandidate;
use PHPUnit\Framework\TestCase;

/**
 * The equitable rule: fewest guardias at this period first, then fewest in total, then by name; and
 * collaborators are only offered when the ordinary guardias cannot cover every group.
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

        $order = array_map(
            static fn (GuardiaCandidate $c): string => $c->teacher->getFullName(),
            $this->assigner->prioritise(4, $candidates),
        );

        self::assertSame(['Ana', 'Bea', 'Diego', 'Carlos'], $order);
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
        self::assertSame(['Ana', 'Bea'], array_map(static fn (GuardiaCandidate $c): string => $c->teacher->getFullName(), $order));
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
            array_map(static fn (GuardiaCandidate $c): string => $c->teacher->getFullName(), $order),
            'collaborators only after every guardia, and only because 3 groups need covering',
        );
    }

    public function testEmptyPoolYieldsNoAssignments(): void
    {
        self::assertSame([], $this->assigner->prioritise(3, []));
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
        return new GuardiaCandidate($this->user($name), false, $slotLoad, $totalLoad);
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
        return new GuardiaCandidate($this->user($name), true, $slotLoad, $totalLoad);
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
