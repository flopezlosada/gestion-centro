<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Guardia\FreeRooms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which rooms are free at an hour, and which are the big ones — both read off the imported timetable,
 * never configured. Pinned against the database because that is where the aggregation happens (and
 * where a DQL aggregate hydrating as a raw scalar has bitten this module before).
 */
final class FreeRoomsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FreeRooms $freeRooms;
    private AcademicYear $year;
    private User $teacher;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->freeRooms = self::getContainer()->get(FreeRooms::class);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-22'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-22'));
        $this->em->persist($this->year);
        $this->teacher = (new User())->setFullName('Docente Uno')->setEmail('uno@centro.test');
        $this->em->persist($this->teacher);
    }

    public function testBigRoomsComeFirstAndCarryTheGroupsTheyHaveHeldAtOnce(): void
    {
        // The assembly hall really does hold several groups at the same time in Peñalara exports; that is
        // the whole evidence for calling it big. A11 only ever holds one.
        $this->lective(Weekday::MONDAY, 1, 'E4A', 'S ACTOS');
        $this->lective(Weekday::MONDAY, 1, 'E4B', 'S ACTOS');
        $this->lective(Weekday::MONDAY, 1, 'E4C', 'S ACTOS');
        $this->lective(Weekday::MONDAY, 1, '1ºA', 'A11');
        $this->em->flush();

        // Period 0: nobody teaches, so every room known to the timetable is free.
        $rooms = $this->freeRooms->atSlot($this->year, Weekday::MONDAY, 0);

        self::assertSame(['S ACTOS', 'A11'], array_column($rooms, 'room'), 'biggest first, then alphabetically');
        self::assertSame([3, 1], array_column($rooms, 'capacity'), 'three groups at once in the hall, one in the classroom');
        self::assertSame([true, true], array_column($rooms, 'free'));
    }

    public function testARoomInUseIsReportedTakenWithWhoIsInIt(): void
    {
        $other = (new User())->setFullName('Docente Dos')->setEmail('dos@centro.test');
        $this->em->persist($other);
        $this->lective(Weekday::MONDAY, 0, '3ºC', 'BIBL', $other);
        $this->lective(Weekday::MONDAY, 0, '1ºA', 'A11');
        $this->em->flush();

        $rooms = $this->freeRooms->atSlot($this->year, Weekday::MONDAY, 0);
        $bibl = array_values(array_filter($rooms, static fn (array $r): bool => 'BIBL' === $r['room']))[0];

        self::assertFalse($bibl['free']);
        self::assertCount(1, $bibl['classes']);
        self::assertSame('3ºC', $bibl['classes'][0]['group']);
        self::assertSame($other->getId(), $bibl['classes'][0]['teacher']->getId(), 'who would have to be moved, and therefore told');
    }

    public function testTheSameRoomIsFreeAtOneHourAndTakenAtAnother(): void
    {
        $this->lective(Weekday::MONDAY, 0, '3ºC', 'BIBL');
        $this->lective(Weekday::MONDAY, 1, '1ºA', 'A11');
        $this->em->flush();

        $free = $this->freeRooms->freeBySlot($this->year, Weekday::MONDAY, [0, 1]);

        self::assertSame(['A11'], array_column($free[0], 'room'), 'BIBL is busy at period 0');
        self::assertSame(['BIBL'], array_column($free[1], 'room'), 'and free at period 1, where A11 is busy');
    }

    public function testAnotherWeekdayDoesNotOccupyTheRoom(): void
    {
        $this->lective(Weekday::TUESDAY, 0, '3ºC', 'BIBL');
        $this->em->flush();

        $free = $this->freeRooms->freeBySlot($this->year, Weekday::MONDAY, [0]);

        self::assertSame(['BIBL'], array_column($free[0], 'room'), 'Tuesday says nothing about Monday');
    }

    public function testCellsWithoutARoomAreIgnored(): void
    {
        // Guardia cells carry no room, and irregular export rows can carry an empty one: neither is a room.
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($this->teacher)->setWeekday(Weekday::MONDAY)->setSlotIndex(0)
            ->setStartsAt(new \DateTimeImmutable('08:25'))->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(ScheduleActivityKind::GUARDIA));
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($this->teacher)->setWeekday(Weekday::MONDAY)->setSlotIndex(1)
            ->setStartsAt(new \DateTimeImmutable('09:20'))->setEndsAt(new \DateTimeImmutable('10:15'))
            ->setKind(ScheduleActivityKind::LECTIVE)->setGroupName('1ºA')->setRoomName(''));
        $this->lective(Weekday::MONDAY, 2, '1ºB', 'A11');
        $this->em->flush();

        self::assertSame(['A11'], array_column($this->freeRooms->atSlot($this->year, Weekday::MONDAY, 0), 'room'));
    }

    public function testClassesInNamesOnlyTheClassesOfThatRoom(): void
    {
        $this->lective(Weekday::MONDAY, 0, '3ºC', 'BIBL');
        $this->lective(Weekday::MONDAY, 0, '1ºA', 'A11');
        $this->em->flush();

        $classes = $this->freeRooms->classesIn($this->year, Weekday::MONDAY, 0, 'BIBL');

        self::assertCount(1, $classes);
        self::assertSame('3ºC', $classes[0]->getGroupName());
        self::assertSame([], $this->freeRooms->classesIn($this->year, Weekday::MONDAY, 1, 'BIBL'), 'nothing there at another hour');
    }

    /**
     * Persists a lective cell for the shared teacher (or another one) in a room.
     *
     * @param Weekday   $weekday   the weekday
     * @param int       $slotIndex the period index
     * @param string    $group     the group short name
     * @param string    $room      the room short name
     * @param User|null $teacher   the teacher, defaulting to the shared one
     */
    private function lective(Weekday $weekday, int $slotIndex, string $group, string $room, ?User $teacher = null): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($teacher ?? $this->teacher)->setWeekday($weekday)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:25'))->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(ScheduleActivityKind::LECTIVE)->setGroupName($group)->setRoomName($room)->setSubjectName('Materia'));
    }
}
