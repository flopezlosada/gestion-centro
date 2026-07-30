<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\Room;
use App\Entity\ScheduleEntry;
use App\Entity\SpacePlan;
use App\Entity\SpacePlanAssignment;
use App\Entity\SpacePlanOption;
use App\Entity\User;
use App\Enum\AssignmentKind;
use App\Enum\ProposalStrategy;
use App\Enum\RoomKind;
use App\Enum\ScheduleActivityKind;
use App\Enum\SpacePlanStatus;
use App\Enum\SubstitutionScope;
use App\Enum\Weekday;
use App\Space\EffectiveTimetable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a teacher really has at a given hour of a given DATE, which is not always what the weekly grid
 * says. Two ways it can differ, and both of them matter to the guardia parte: an approved plan can have
 * moved the lesson to another room, and it can have replaced the group's timetable altogether.
 *
 * A plan that is not approved — or one whose lines belong to an option nobody chose — must change nothing
 * at all: that is the whole meaning of approving one.
 */
final class EffectiveTimetableTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EffectiveTimetable $timetable;
    private AcademicYear $year;
    private User $teacher;

    /** A Monday inside the test course. */
    private \DateTimeImmutable $monday;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->timetable = self::getContainer()->get(EffectiveTimetable::class);

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

        $this->teacher = (new User())->setFullName('Rosa Aula Vega')->setEmail('rosa@centro.test');
        $this->em->persist($this->teacher);
        $this->em->flush();
    }

    public function testWithNoPlansItIsJustTheTimetable(): void
    {
        $this->lective(0, 'E1A', '2IN5');
        $this->em->flush();

        $lessons = $this->timetable->forTeacherAt($this->year, $this->teacher, $this->monday, 0);

        self::assertCount(1, $lessons);
        self::assertSame('2IN5', $lessons[0]->roomName());
        self::assertFalse($lessons[0]->isRelocated());
    }

    public function testAnApprovedPlanMovesTheLessonToItsNewRoom(): void
    {
        $entry = $this->lective(0, 'E1A', '2IN5');
        $plan = $this->approvedPlan();
        $this->line($plan, $entry, $this->room('0LC7'), 0, 'E1A');
        $this->em->flush();

        $lessons = $this->timetable->forTeacherAt($this->year, $this->teacher, $this->monday, 0);

        self::assertCount(1, $lessons, 'the lesson still happens');
        self::assertSame('0LC7', $lessons[0]->roomName(), 'in the room the plan sends it to');
        self::assertTrue($lessons[0]->isRelocated());
        self::assertSame('2IN5', $lessons[0]->entry->getRoomName(), 'the weekly grid is untouched');
    }

    public function testADraftPlanChangesNothing(): void
    {
        $entry = $this->lective(0, 'E1A', '2IN5');
        $plan = $this->approvedPlan()->setStatus(SpacePlanStatus::DRAFT);
        $this->line($plan, $entry, $this->room('0LC7'), 0, 'E1A');
        $this->em->flush();

        $lessons = $this->timetable->forTeacherAt($this->year, $this->teacher, $this->monday, 0);

        self::assertSame('2IN5', $lessons[0]->roomName(), 'until it is approved it is only a proposal');
    }

    public function testAPlanOnAnotherDayChangesNothing(): void
    {
        $entry = $this->lective(0, 'E1A', '2IN5');
        $plan = $this->approvedPlan();
        $this->line($plan, $entry, $this->room('0LC7'), 0, 'E1A');
        $this->em->flush();

        // The following Monday: same weekday, same period, no plan.
        $lessons = $this->timetable->forTeacherAt($this->year, $this->teacher, $this->monday->modify('+7 days'), 0);

        self::assertSame('2IN5', $lessons[0]->roomName());
    }

    public function testAGroupWhoseTimetableThePlanReplacesHasNoLesson(): void
    {
        // Exam week: 2º de Bachillerato has no ordinary lessons those days. Registering an absence against
        // one would create a parte line, a task and a notice for a class nobody was going to teach.
        $this->lective(0, 'B2A', '2IN5');
        $plan = $this->approvedPlan();
        $plan->setSubstitutionScope(SubstitutionScope::GROUPS)->setScopeGroupNames(['B2A']);
        $this->em->flush();

        self::assertSame([], $this->timetable->forTeacherAt($this->year, $this->teacher, $this->monday, 0));
    }

    public function testOnlyTheGroupsInScopeLoseTheirLessons(): void
    {
        $this->lective(0, 'B2A', '2IN5');
        $this->lective(0, 'E1A', 'S ACTOS');
        $plan = $this->approvedPlan();
        $plan->setSubstitutionScope(SubstitutionScope::GROUPS)->setScopeGroupNames(['B2A']);
        $this->em->flush();

        $lessons = $this->timetable->forTeacherAt($this->year, $this->teacher, $this->monday, 0);

        self::assertCount(1, $lessons, 'the group outside the scope carries on');
        self::assertSame('E1A', $lessons[0]->entry->getGroupName());
    }

    /**
     * Persists a lective Monday cell for the test teacher.
     *
     * @param int    $slotIndex the period index
     * @param string $group     the group
     * @param string $roomName  the room as the timetable spells it
     *
     * @return ScheduleEntry the persisted cell
     */
    private function lective(int $slotIndex, string $group, string $roomName): ScheduleEntry
    {
        $entry = (new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($this->teacher)
            ->setWeekday(Weekday::MONDAY)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:00'))->setEndsAt(new \DateTimeImmutable('09:00'))
            ->setKind(ScheduleActivityKind::LECTIVE)
            ->setGroupName($group)->setRoomName($roomName)->setSubjectName('Inglés');
        $this->em->persist($entry);

        return $entry;
    }

    /**
     * An approved plan covering the test Monday, with its chosen option.
     *
     * @return SpacePlan the plan
     */
    private function approvedPlan(): SpacePlan
    {
        $plan = (new SpacePlan())
            ->setAcademicYear($this->year)
            ->setCreatedBy($this->teacher)
            ->setTitle('Prueba externa')
            ->setDateFrom($this->monday)
            ->setDateTo($this->monday)
            ->setStatus(SpacePlanStatus::APPROVED);
        $option = (new SpacePlanOption())->setLabel('Opción A')->setStrategy(ProposalStrategy::NEAREST);
        $plan->addOption($option);
        $plan->setChosenOption($option);
        $this->em->persist($plan);
        $this->em->persist($option);

        return $plan;
    }

    /**
     * A relocation line of the plan's chosen option, moving one timetable cell to a space.
     *
     * @param SpacePlan     $plan      the plan
     * @param ScheduleEntry $source    the cell being moved
     * @param Room          $room      the destination
     * @param int           $slotIndex the period index
     * @param string        $group     the group
     */
    private function line(SpacePlan $plan, ScheduleEntry $source, Room $room, int $slotIndex, string $group): void
    {
        $option = $plan->getChosenOption();
        self::assertNotNull($option);

        $assignment = (new SpacePlanAssignment())
            ->setDate($this->monday)
            ->setSlotIndex($slotIndex)
            ->setKind(AssignmentKind::RELOCATION)
            ->setRoom($room)
            ->setOriginRoomName((string) $source->getRoomName())
            ->setGroupNames($group)
            ->setSourceEntry($source)
            ->setTeacher($this->teacher);
        $option->addAssignment($assignment);
        $this->em->persist($assignment);
    }

    /**
     * Persists a space card.
     *
     * @param string $code the room code
     *
     * @return Room the persisted card
     */
    private function room(string $code): Room
    {
        $room = (new Room())->setCode($code)->setName($code)->setKind(RoomKind::CLASSROOM);
        $this->em->persist($room);

        return $room;
    }
}
