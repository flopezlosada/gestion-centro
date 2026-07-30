<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Room;
use App\Enum\ProposalStrategy;
use App\Enum\RoomKind;
use App\Space\Displacement;
use App\Space\RelocationSolver;
use PHPUnit\Framework\TestCase;

/**
 * The rules the relocation engine has to obey, exercised without a database because the solver is pure.
 *
 * The ones that matter to the centre: a split group is not scattered across the building, a room is
 * never double-booked, capacity is respected when it is known and ignored when it is not, and each
 * criterion actually optimises for what its name says.
 */
final class RelocationSolverTest extends TestCase
{
    private RelocationSolver $solver;
    private \DateTimeImmutable $monday;

    protected function setUp(): void
    {
        $this->solver = new RelocationSolver();
        $this->monday = new \DateTimeImmutable('2026-01-12');
    }

    public function testSendsADisplacedLessonToAFreeRoom(): void
    {
        $origin = $this->room(1, '2IN5', RoomKind::CLASSROOM, 30);
        $target = $this->room(2, '0LC7', RoomKind::CLASSROOM, 30);
        $displacement = $this->displacement($origin, 'E1A');

        $placements = $this->solver->solve([$displacement], [$displacement->moment() => [$target]], ProposalStrategy::NEAREST);

        self::assertCount(1, $placements);
        self::assertSame('0LC7', $placements[0]->room?->getCode());
        self::assertTrue($placements[0]->isResolved());
    }

    public function testKeepsASplitGroupTogether(): void
    {
        // Two cells in the SAME room at the same period (a desdoble): both must land in one room, or
        // half the class ends up on the other side of the centre.
        $origin = $this->room(1, 'S ACTOS', RoomKind::ASSEMBLY_HALL, 200);
        $first = $this->displacement($origin, 'E1A');
        $second = $this->displacement($origin, 'E1B');
        $free = [$this->room(2, '0LC1', RoomKind::CLASSROOM, 200), $this->room(3, '0LC7', RoomKind::CLASSROOM, 200)];

        $placements = $this->solver->solve([$first, $second], [$first->moment() => $free], ProposalStrategy::NEAREST);

        self::assertSame($placements[0]->room?->getCode(), $placements[1]->room?->getCode(), 'both halves stay together');
    }

    public function testNeverPutsTwoDifferentRoomsWorthOfPeopleInTheSameRoom(): void
    {
        $firstOrigin = $this->room(1, '2IN5', RoomKind::CLASSROOM, 30);
        $secondOrigin = $this->room(2, '2IN7', RoomKind::CLASSROOM, 30);
        $first = $this->displacement($firstOrigin, 'E1A');
        $second = $this->displacement($secondOrigin, 'E1B');
        $free = [$this->room(3, '0LC1', RoomKind::CLASSROOM, 30), $this->room(4, '0LC7', RoomKind::CLASSROOM, 30)];

        $placements = $this->solver->solve([$first, $second], [$first->moment() => $free], ProposalStrategy::NEAREST);

        self::assertNotSame($placements[0]->room?->getCode(), $placements[1]->room?->getCode());
    }

    public function testLeavesALessonUnplacedWhenThereIsNowhereToGo(): void
    {
        $origin = $this->room(1, '2IN5', RoomKind::CLASSROOM, 30);
        $displacement = $this->displacement($origin, 'E1A');

        $placements = $this->solver->solve([$displacement], [$displacement->moment() => []], ProposalStrategy::NEAREST);

        self::assertFalse($placements[0]->isResolved(), 'nowhere to go is an answer, not a crash');
        self::assertNull($placements[0]->room);
    }

    public function testRefusesARoomTooSmallForTheGroupItIsMoving(): void
    {
        // The centre has no enrolment data, so the seats needed are inferred from the origin room.
        $origin = $this->room(1, 'S ACTOS', RoomKind::ASSEMBLY_HALL, 120);
        $displacement = $this->displacement($origin, 'E1A');
        $free = [$this->room(2, '0LC1', RoomKind::CLASSROOM, 30), $this->room(3, 'GIM', RoomKind::GYM, 150)];

        $placements = $this->solver->solve([$displacement], [$displacement->moment() => $free], ProposalStrategy::NEAREST);

        self::assertSame('GIM', $placements[0]->room?->getCode(), 'the 30-seat classroom cannot hold a hall-sized group');
    }

    public function testOffersARoomOfUnknownCapacityRatherThanHidingIt(): void
    {
        // Most cards start with no capacity (the export carries none): refusing to propose them would
        // leave the engine with nothing to say on a freshly imported timetable.
        $origin = $this->room(1, 'S ACTOS', RoomKind::ASSEMBLY_HALL, 120);
        $displacement = $this->displacement($origin, 'E1A');
        $free = [$this->room(2, '2IN5', RoomKind::OTHER, null)];

        $placements = $this->solver->solve([$displacement], [$displacement->moment() => $free], ProposalStrategy::NEAREST);

        self::assertSame('2IN5', $placements[0]->room?->getCode());
    }

    public function testNearestPrefersTheSameFloorOfTheSameBuilding(): void
    {
        $origin = $this->room(1, '2IN5', RoomKind::CLASSROOM, 30, 'A', 1);
        $displacement = $this->displacement($origin, 'E1A');
        $far = $this->room(2, 'B-LEJOS', RoomKind::CLASSROOM, 30, 'B', 0);
        $near = $this->room(3, 'A-CERCA', RoomKind::CLASSROOM, 30, 'A', 1);

        $placements = $this->solver->solve([$displacement], [$displacement->moment() => [$far, $near]], ProposalStrategy::NEAREST);

        self::assertSame('A-CERCA', $placements[0]->room?->getCode());
    }

    public function testPreserveSpecialisedGoesFurtherRatherThanTakeALab(): void
    {
        $origin = $this->room(1, '2IN5', RoomKind::CLASSROOM, 30, 'A', 1);
        $displacement = $this->displacement($origin, 'E1A');
        $labNextDoor = $this->room(2, 'LABQ', RoomKind::LAB, 30, 'A', 1);
        $classroomFarAway = $this->room(3, 'B-LEJOS', RoomKind::CLASSROOM, 30, 'B', 0);

        $nearest = $this->solver->solve([$displacement], [$displacement->moment() => [$labNextDoor, $classroomFarAway]], ProposalStrategy::NEAREST);
        $preserve = $this->solver->solve([$displacement], [$displacement->moment() => [$labNextDoor, $classroomFarAway]], ProposalStrategy::PRESERVE_SPECIALISED);

        self::assertSame('LABQ', $nearest[0]->room?->getCode(), 'nearest takes the lab next door');
        self::assertSame('B-LEJOS', $preserve[0]->room?->getCode(), 'preserving specialised walks further');
    }

    public function testStableRoomKeepsAGroupInOneRoomAcrossDays(): void
    {
        $origin = $this->room(1, '2IN5', RoomKind::CLASSROOM, 30, 'A', 1);
        $tuesday = $this->monday->modify('+1 day');
        $mondayLesson = $this->displacement($origin, 'E1A', 0, $this->monday);
        $tuesdayLesson = $this->displacement($origin, 'E1A', 0, $tuesday);

        // On Tuesday the near room is free too, but so is a nearer one; STABLE_ROOM must still go back
        // to Monday's room.
        $home = $this->room(2, 'HOGAR', RoomKind::CLASSROOM, 30, 'B', 0);
        $nearer = $this->room(3, 'A-CERCA', RoomKind::CLASSROOM, 30, 'A', 1);
        $free = [
            $mondayLesson->moment() => [$home],
            $tuesdayLesson->moment() => [$home, $nearer],
        ];

        $stable = $this->solver->solve([$mondayLesson, $tuesdayLesson], $free, ProposalStrategy::STABLE_ROOM);
        $nearest = $this->solver->solve([$mondayLesson, $tuesdayLesson], $free, ProposalStrategy::NEAREST);

        self::assertSame('HOGAR', $stable[1]->room?->getCode(), 'the group walks to the same door every day');
        self::assertSame('A-CERCA', $nearest[1]->room?->getCode(), 'nearest happily moves it');
    }

    public function testPlacesTheHardestLessonFirst(): void
    {
        // Two lessons, one of which only fits in the single big room. Placing the easy one first into
        // that big room would leave the hard one homeless; hardest-first fits both.
        $smallOrigin = $this->room(1, 'PEQ', RoomKind::CLASSROOM, 20);
        $bigOrigin = $this->room(2, 'GRANDE', RoomKind::CLASSROOM, 100);
        $easy = $this->displacement($smallOrigin, 'E1A');
        $hard = $this->displacement($bigOrigin, 'E1B');
        $free = [$this->room(3, 'CHICA', RoomKind::CLASSROOM, 25), $this->room(4, 'ENORME', RoomKind::CLASSROOM, 120)];

        $placements = $this->solver->solve([$easy, $hard], [$easy->moment() => $free], ProposalStrategy::NEAREST);

        self::assertTrue($placements[0]->isResolved());
        self::assertTrue($placements[1]->isResolved(), 'both fit when the hardest is placed first');
        self::assertSame('ENORME', $placements[1]->room?->getCode());
    }

    /**
     * A room with an id, without touching the database.
     *
     * @param int         $id       the id to give it
     * @param string      $code     its code
     * @param RoomKind    $kind     its kind
     * @param int|null    $capacity its capacity, or null when unknown
     * @param string|null $building its building
     * @param int|null    $floor    its floor
     *
     * @return Room the room
     */
    private function room(int $id, string $code, RoomKind $kind, ?int $capacity, ?string $building = null, ?int $floor = null): Room
    {
        $room = (new Room())->setCode($code)->setName($code)->setKind($kind)->setCapacity($capacity)
            ->setBuilding($building)->setFloor($floor);
        // The solver keys rooms by id, which only the database sets; give it one without persisting.
        $reflection = new \ReflectionProperty(Room::class, 'id');
        $reflection->setValue($room, $id);

        return $room;
    }

    /**
     * A displacement of a group out of a room.
     *
     * @param Room                    $origin the room being left
     * @param string                  $group  the group
     * @param int                     $slot   the period index
     * @param \DateTimeImmutable|null $date   the day, defaulting to the fixture Monday
     *
     * @return Displacement the displacement
     */
    private function displacement(Room $origin, string $group, int $slot = 0, ?\DateTimeImmutable $date = null): Displacement
    {
        return new Displacement($date ?? $this->monday, $slot, $origin, $group, 'Materia');
    }
}
