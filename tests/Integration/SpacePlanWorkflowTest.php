<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\Room;
use App\Entity\ScheduleEntry;
use App\Entity\SpacePlan;
use App\Entity\SpacePlanActivity;
use App\Entity\User;
use App\Enum\RoomKind;
use App\Enum\ScheduleActivityKind;
use App\Enum\SpacePlanStatus;
use App\Enum\SubstitutionScope;
use App\Enum\Weekday;
use App\Space\RoomOccupancy;
use App\Space\SpacePlanWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The full round of a plan against a real timetable: what an event displaces, what the alternatives say,
 * and — the part that matters — that NOTHING changes for anybody until a plan is approved, and that
 * everything changes the moment it is.
 */
final class SpacePlanWorkflowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SpacePlanWorkflow $workflow;
    private RoomOccupancy $occupancy;
    private AcademicYear $year;
    private User $teacher;
    private \DateTimeImmutable $monday;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->workflow = self::getContainer()->get(SpacePlanWorkflow::class);
        $this->occupancy = self::getContainer()->get(RoomOccupancy::class);

        $this->monday = new \DateTimeImmutable('2026-01-12');
        self::assertSame('1', $this->monday->format('N'), 'the fixture date must be a Monday');

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-23'));
        $this->em->persist($this->year);

        $this->teacher = (new User())->setFullName('Rosa Aula Vega')->setEmail('rosa.aula@educa.madrid.org');
        $this->em->persist($this->teacher);
        $this->em->flush();
    }

    public function testAnActivityDisplacesTheLessonInItsRoomAndTheAlternativesRelocateIt(): void
    {
        $hall = $this->room('S ACTOS', RoomKind::ASSEMBLY_HALL, 100);
        $this->room('0LC1', RoomKind::CLASSROOM, 100);
        $this->lective(Weekday::MONDAY, 0, $hall, 'E1A');
        $plan = $this->plan();
        $this->activity($plan, 'Prueba EOI', $hall, [0]);
        $this->em->flush();

        $options = $this->workflow->generate($plan);

        self::assertNotEmpty($options);
        $option = $options[0];
        self::assertSame(1, $option->metric('movedClasses'));
        self::assertSame(0, $option->metric('unresolved'));
        self::assertSame(SpacePlanStatus::PROPOSED, $plan->getStatus());

        $relocation = null;
        foreach ($option->getAssignments() as $assignment) {
            if ('E1A' === $assignment->getGroupNames()) {
                $relocation = $assignment;
            }
        }
        self::assertNotNull($relocation, 'the displaced lesson has a line of its own');
        self::assertSame('S ACTOS', $relocation->getOriginRoomName());
        self::assertSame('0LC1', $relocation->getRoom()?->getCode());
        self::assertSame($this->teacher->getId(), $relocation->getTeacher()?->getId(), 'the teacher is on the line, to be told later');
    }

    public function testNothingChangesForAnybodyUntilThePlanIsApproved(): void
    {
        $hall = $this->room('S ACTOS', RoomKind::ASSEMBLY_HALL, 100);
        $this->room('0LC1', RoomKind::CLASSROOM, 100);
        $this->lective(Weekday::MONDAY, 0, $hall, 'E1A');
        $plan = $this->plan();
        $this->activity($plan, 'Prueba EOI', $hall, [0]);
        $this->em->flush();
        $this->workflow->generate($plan);

        // Proposed, not approved: the effective timetable still says the lesson is in the hall.
        $before = $this->occupancy->at($this->year, $this->monday, 0);
        self::assertSame(['S ACTOS'], $this->codes($before->occupied));
        self::assertSame(['0LC1'], $this->freeCodes($before));

        $this->workflow->approve($plan, $plan->getOptions()->toArray()[0], $this->teacher);

        $after = $this->occupancy->at($this->year, $this->monday, 0);
        self::assertSame(['0LC1', 'S ACTOS'], $this->codes($after->occupied), 'both are taken now');
        self::assertSame([], $this->freeCodes($after));
        foreach ($after->occupied as $occupation) {
            self::assertTrue($occupation->isPlanned(), 'both are occupied because of the plan');
        }
    }

    public function testRegeneratingKeepsAnOptionSomebodyEditedByHand(): void
    {
        $hall = $this->room('S ACTOS', RoomKind::ASSEMBLY_HALL, 100);
        $elsewhere = $this->room('0LC7', RoomKind::CLASSROOM, 100);
        $this->room('0LC1', RoomKind::CLASSROOM, 100);
        $this->lective(Weekday::MONDAY, 0, $hall, 'E1A');
        $plan = $this->plan();
        $this->activity($plan, 'Prueba EOI', $hall, [0]);
        $this->em->flush();

        $options = $this->workflow->generate($plan);
        $edited = $options[0];
        foreach ($edited->getAssignments() as $assignment) {
            if ('E1A' === $assignment->getGroupNames()) {
                $assignment->setRoom($elsewhere);
                $this->workflow->markEdited($assignment);
            }
        }
        $this->em->flush();
        $editedId = $edited->getId();

        $this->workflow->generate($plan);

        $ids = array_map(static fn ($o): ?int => $o->getId(), $plan->getOptions()->toArray());
        self::assertContains($editedId, $ids, 'a decision somebody made is not thrown away by regenerating');
    }

    public function testApprovingRefusesToDoubleBookARoom(): void
    {
        $hall = $this->room('S ACTOS', RoomKind::ASSEMBLY_HALL, 100);
        $this->room('0LC1', RoomKind::CLASSROOM, 100);
        $this->lective(Weekday::MONDAY, 0, $hall, 'E1A');

        $first = $this->plan('Primero');
        $this->activity($first, 'Prueba EOI', $hall, [0]);
        $second = $this->plan('Segundo');
        $this->activity($second, 'Charla', $hall, [0]);
        $this->em->flush();

        $this->workflow->generate($first);
        $this->workflow->approve($first, $first->getOptions()->toArray()[0], $this->teacher);

        $this->workflow->generate($second);
        $clashes = $this->workflow->clashes($second, $second->getOptions()->toArray()[0]);
        self::assertNotEmpty($clashes, 'the clash is visible before approving, not after');

        $this->expectException(\LogicException::class);
        $this->workflow->approve($second, $second->getOptions()->toArray()[0], $this->teacher);
    }

    public function testAGroupWhoseTimetableThePlanReplacesFreesItsRoomInsteadOfMoving(): void
    {
        // Exam week: 2º de Bachillerato sits exams, so its ordinary lessons do not happen and the rooms
        // they would have used are exactly what the displaced lessons need.
        $english = $this->room('2IN5', RoomKind::CLASSROOM, 30);
        $spare = $this->room('0LC1', RoomKind::CLASSROOM, 30);
        $this->lective(Weekday::MONDAY, 0, $english, 'E1A');
        $this->lective(Weekday::MONDAY, 0, $spare, 'B2A');

        $plan = $this->plan();
        $plan->setSubstitutionScope(SubstitutionScope::GROUPS)->setScopeGroupNames(['B2A']);
        $this->activity($plan, 'Exámenes de 2º Bach', $english, [0]);
        $this->em->flush();

        $options = $this->workflow->generate($plan);
        $relocation = null;
        foreach ($options[0]->getAssignments() as $assignment) {
            if ('E1A' === $assignment->getGroupNames()) {
                $relocation = $assignment;
            }
        }

        self::assertNotNull($relocation);
        self::assertSame('0LC1', $relocation->getRoom()?->getCode(), 'the room the exam group vacated is the one used');

        $moved = [];
        foreach ($options[0]->getAssignments() as $assignment) {
            $moved[] = $assignment->getGroupNames();
        }
        self::assertNotContains('B2A', $moved, 'the group sitting exams is not relocated: it has no lesson');
    }

    /**
     * The codes of the occupied rooms, sorted for a stable comparison.
     *
     * @param list<\App\Space\RoomOccupation> $occupied the occupations
     *
     * @return list<string> their room codes
     */
    private function codes(array $occupied): array
    {
        $codes = array_map(static fn ($o): string => $o->room->getCode(), $occupied);
        sort($codes);

        return $codes;
    }

    /**
     * The codes of the free rooms, sorted.
     *
     * @param \App\Space\RoomAvailability $availability the availability
     *
     * @return list<string> the free room codes
     */
    private function freeCodes(\App\Space\RoomAvailability $availability): array
    {
        $codes = array_map(static fn (Room $r): string => $r->getCode(), $availability->free);
        sort($codes);

        return $codes;
    }

    private function plan(string $title = 'Plan de prueba'): SpacePlan
    {
        $plan = (new SpacePlan())
            ->setAcademicYear($this->year)
            ->setCreatedBy($this->teacher)
            ->setTitle($title)
            ->setDateFrom($this->monday)
            ->setDateTo($this->monday)
            ->setSlotFrom(0)
            ->setSlotTo(0);
        $this->em->persist($plan);

        return $plan;
    }

    /**
     * @param list<int> $slots the periods it takes
     */
    private function activity(SpacePlan $plan, string $title, Room $room, array $slots): SpacePlanActivity
    {
        $activity = (new SpacePlanActivity())
            ->setTitle($title)
            ->setRoom($room)
            ->setFixedDate($this->monday)
            ->setFixedSlots($slots);
        $plan->addActivity($activity);
        $this->em->persist($activity);

        return $activity;
    }

    private function room(string $code, RoomKind $kind, ?int $capacity): Room
    {
        $room = (new Room())->setCode($code)->setName($code)->setKind($kind)->setCapacity($capacity);
        $this->em->persist($room);

        return $room;
    }

    private function lective(Weekday $weekday, int $slotIndex, Room $room, string $group): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($this->teacher)
            ->setWeekday($weekday)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:00'))->setEndsAt(new \DateTimeImmutable('09:00'))
            ->setKind(ScheduleActivityKind::LECTIVE)
            ->setGroupName($group)->setRoomName($room->getCode())->setSubjectName('Materia')
            ->setRoom($room));
    }
}
