<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Repository\ScheduleEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The timetable reads must be scoped to the course the queried date falls into: two courses can carry
 * the very same teacher on the very same weekday and period, and a read for one course must never
 * surface the other's rows. This is the scoping invariant the whole "tie the timetable to the academic
 * year" task exists to guarantee.
 */
final class ScheduleEntryScopingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ScheduleEntryRepository $repo;
    private AcademicYear $yearA;
    private AcademicYear $yearB;
    private User $teacher;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(ScheduleEntryRepository::class);

        $this->yearA = $this->academicYear('2025-2026');
        $this->yearB = $this->academicYear('2026-2027');
        $this->em->persist($this->yearA);
        $this->em->persist($this->yearB);

        $this->teacher = (new User())->setFullName('Ada Lovelace')->setEmail('ada@centro.test');
        $this->em->persist($this->teacher);

        // Same teacher, same Monday first period in BOTH courses — but different content and, in year B,
        // an extra second period that year A does not have.
        $this->entry($this->yearA, Weekday::MONDAY, 0, ScheduleActivityKind::LECTIVE, '08:25', '09:20', '1ºA', 'A10');
        $this->entry($this->yearA, Weekday::MONDAY, 0, ScheduleActivityKind::GUARDIA, '08:25', '09:20');
        $this->entry($this->yearB, Weekday::MONDAY, 0, ScheduleActivityKind::LECTIVE, '08:00', '09:00', '2ºB', 'B20');
        $this->entry($this->yearB, Weekday::MONDAY, 1, ScheduleActivityKind::GUARDIA, '09:00', '10:00');

        $this->em->flush();
    }

    public function testDutyPoolIsScopedToItsCourse(): void
    {
        // Year A has a Monday-slot-0 guardia; year B does not (its guardia is on slot 1).
        self::assertCount(1, $this->repo->dutyPoolAt($this->yearA, Weekday::MONDAY, 0));
        self::assertCount(0, $this->repo->dutyPoolAt($this->yearB, Weekday::MONDAY, 0));
        self::assertCount(1, $this->repo->dutyPoolAt($this->yearB, Weekday::MONDAY, 1));
        self::assertCount(0, $this->repo->dutyPoolAt($this->yearA, Weekday::MONDAY, 1));
    }

    public function testLectiveLookupReturnsTheRightCoursesCell(): void
    {
        self::assertSame('1ºA', $this->repo->lectiveEntriesAt($this->yearA, $this->teacher, Weekday::MONDAY, 0)[0]->getGroupName());
        self::assertSame('2ºB', $this->repo->lectiveEntriesAt($this->yearB, $this->teacher, Weekday::MONDAY, 0)[0]->getGroupName());
    }

    public function testDistinctSlotsAreScopedToTheirCourse(): void
    {
        self::assertCount(1, $this->repo->distinctSlots($this->yearA), 'year A only has slot 0');
        self::assertCount(2, $this->repo->distinctSlots($this->yearB), 'year B has slots 0 and 1');
    }

    /**
     * A well-ordered course structure for the given school year.
     *
     * @param string $schoolYear the course code in "YYYY-YYYY" form
     */
    private function academicYear(string $schoolYear): AcademicYear
    {
        $start = (int) substr($schoolYear, 0, 4);

        return (new AcademicYear())
            ->setSchoolYear($schoolYear)
            ->setTerm1Start(new \DateTimeImmutable($start.'-09-15'))
            ->setTerm1End(new \DateTimeImmutable($start.'-12-22'))
            ->setTerm2Start(new \DateTimeImmutable(($start + 1).'-01-08'))
            ->setTerm2End(new \DateTimeImmutable(($start + 1).'-03-27'))
            ->setTerm3Start(new \DateTimeImmutable(($start + 1).'-04-07'))
            ->setTerm3End(new \DateTimeImmutable(($start + 1).'-06-22'));
    }

    /**
     * Persists one schedule cell for the shared teacher.
     */
    private function entry(AcademicYear $year, Weekday $weekday, int $slot, ScheduleActivityKind $kind, string $start, string $end, ?string $group = null, ?string $room = null): void
    {
        $this->em->persist(
            (new ScheduleEntry())
                ->setAcademicYear($year)
                ->setTeacher($this->teacher)
                ->setWeekday($weekday)
                ->setSlotIndex($slot)
                ->setStartsAt(new \DateTimeImmutable($start))
                ->setEndsAt(new \DateTimeImmutable($end))
                ->setKind($kind)
                ->setGroupName($group)
                ->setRoomName($room)
        );
    }
}
