<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\Room;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\RoomKind;
use App\Enum\RoomSize;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Space\RoomOccupancy;
use App\Space\RoomSynchroniser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What "free at this hour" means: the timetable's lessons occupy their room, a guardia occupies none,
 * and a room several lessons share (a split group, a whole-level session) is reported once. On top of
 * that, "free" and "usable" are different answers — a court is free most of the day and no group can
 * be sent there.
 */
final class RoomOccupancyTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RoomOccupancy $occupancy;
    private AcademicYear $year;
    private User $teacher;
    private User $other;

    /** A Monday inside the test course. */
    private \DateTimeImmutable $monday;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
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
        $this->other = (new User())->setFullName('Luis Desdoble Gil')->setEmail('luis.desdoble@educa.madrid.org');
        $this->em->persist($this->teacher);
        $this->em->persist($this->other);
        $this->em->flush();
    }

    public function testAnOccupiedRoomIsNotFreeAndNamesWhoIsInIt(): void
    {
        $this->room('0LC1', RoomKind::CLASSROOM, 30);
        $this->room('0LC7', RoomKind::CLASSROOM, 30);
        $this->lective($this->teacher, Weekday::MONDAY, 0, '0LC1', 'E1A', 'Lengua');
        $this->sync();

        $availability = $this->occupancy->at($this->year, $this->monday, 0);

        self::assertSame(['0LC7'], $this->codes($availability->free));
        self::assertCount(1, $availability->occupied);
        self::assertSame('0LC1', $availability->occupied[0]->room->getCode());
        self::assertSame('E1A', $availability->occupied[0]->groups());
        self::assertSame('Rosa Aula Vega', $availability->occupied[0]->teachers());
        self::assertSame('Lengua', $availability->occupied[0]->subjects());
    }

    public function testSeveralLessonsInOneRoomAreReportedOnce(): void
    {
        // A split group (desdoble) or a whole-level activity: Peñalara lists one cell per group.
        $this->room('S ACTOS', RoomKind::ASSEMBLY_HALL, 200);
        $this->lective($this->teacher, Weekday::MONDAY, 0, 'S ACTOS', 'E1A', 'Teatro');
        $this->lective($this->other, Weekday::MONDAY, 0, 'S ACTOS', 'E1B', 'Teatro');
        $this->sync();

        $availability = $this->occupancy->at($this->year, $this->monday, 0);

        self::assertCount(1, $availability->occupied, 'one room, one row');
        self::assertSame('E1A, E1B', $availability->occupied[0]->groups());
        self::assertSame('Rosa Aula Vega, Luis Desdoble Gil', $availability->occupied[0]->teachers());
        self::assertSame('Teatro', $availability->occupied[0]->subjects(), 'the same subject is not repeated');
    }

    public function testAGuardiaOccupiesNoRoom(): void
    {
        $this->room('0LC1', RoomKind::CLASSROOM, 30);
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($this->teacher)
            ->setWeekday(Weekday::MONDAY)->setSlotIndex(0)
            ->setStartsAt(new \DateTimeImmutable('08:00'))->setEndsAt(new \DateTimeImmutable('09:00'))
            ->setKind(ScheduleActivityKind::GUARDIA));
        $this->em->flush();

        $availability = $this->occupancy->at($this->year, $this->monday, 0);

        self::assertSame(['0LC1'], $this->codes($availability->free));
        self::assertSame([], $availability->occupied);
    }

    public function testAnotherPeriodAndAnotherWeekdayAreDifferentAnswers(): void
    {
        $this->room('0LC1', RoomKind::CLASSROOM, 30);
        $this->lective($this->teacher, Weekday::MONDAY, 0, '0LC1', 'E1A', 'Lengua');
        $this->sync();

        self::assertSame([], $this->codes($this->occupancy->at($this->year, $this->monday, 0)->free));
        self::assertSame(['0LC1'], $this->codes($this->occupancy->at($this->year, $this->monday, 1)->free), 'next period');
        self::assertSame(['0LC1'], $this->codes($this->occupancy->at($this->year, $this->monday->modify('+1 day'), 0)->free), 'Tuesday');
    }

    public function testDeactivatedRoomsAreNotOffered(): void
    {
        $this->room('0LC1', RoomKind::CLASSROOM, 30)->setActive(false);
        $this->em->flush();

        $availability = $this->occupancy->at($this->year, $this->monday, 0);

        self::assertSame([], $availability->free, 'a retired room is not free, it is gone');
    }

    public function testFreeIsNotTheSameAsUsable(): void
    {
        $this->room('PIST ROJ', RoomKind::OUTDOOR, 200)->setAssignable(false);
        $this->room('0LC1', RoomKind::CLASSROOM, 30);
        $this->em->flush();

        $availability = $this->occupancy->at($this->year, $this->monday, 0);

        self::assertSame(['0LC1', 'PIST ROJ'], $this->codes($availability->free), 'both are free');
        self::assertSame(['0LC1'], $this->codes($availability->assignable()), 'only one can host a group');
    }

    public function testCandidatesPreferOrdinaryClassroomsAndTheTightestFit(): void
    {
        $this->room('LABQ', RoomKind::LAB, 40)->setSize(RoomSize::TWO_GROUPS);
        $this->room('0LC1', RoomKind::CLASSROOM, 30)->setSize(RoomSize::ONE_GROUP);
        $this->room('0LC7', RoomKind::CLASSROOM, 60)->setSize(RoomSize::TWO_GROUPS);
        $this->em->flush();

        // Ordinary rooms before specialised ones, and the tightest fit first so the big rooms stay free.
        self::assertSame(['0LC1', '0LC7', 'LABQ'], $this->codes($this->occupancy->candidatesAt($this->year, $this->monday, 0)));

        // Asking for two whole groups rules out the one-group classroom, not the lab.
        self::assertSame(['0LC7', 'LABQ'], $this->codes($this->occupancy->candidatesAt($this->year, $this->monday, 0, 2)));
    }

    public function testARoomWithoutASizeIsStillOfferedRatherThanSilentlyDropped(): void
    {
        // Every card starts unclassified, so an incomplete one is the normal case, not an error.
        $this->room('2IN5', RoomKind::OTHER, null);
        $this->em->flush();

        self::assertSame(['2IN5'], $this->codes($this->occupancy->candidatesAt($this->year, $this->monday, 0, 3)));
    }

    /**
     * Persists a space card.
     *
     * @param string   $code     the room code
     * @param RoomKind $kind     the kind
     * @param int|null $capacity the capacity, or null when unknown
     *
     * @return Room the persisted card
     */
    private function room(string $code, RoomKind $kind, ?int $capacity): Room
    {
        $room = (new Room())->setCode($code)->setName($code)->setKind($kind)->setCapacity($capacity);
        $this->em->persist($room);

        return $room;
    }

    /**
     * Persists a lective cell in the test course.
     *
     * @param User    $teacher   the teacher
     * @param Weekday $weekday   the weekday
     * @param int     $slotIndex the period index
     * @param string  $roomName  the room name as the timetable spells it
     * @param string  $group     the group
     * @param string  $subject   the subject
     */
    private function lective(User $teacher, Weekday $weekday, int $slotIndex, string $roomName, string $group, string $subject): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($teacher)
            ->setWeekday($weekday)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:00'))->setEndsAt(new \DateTimeImmutable('09:00'))
            ->setKind(ScheduleActivityKind::LECTIVE)
            ->setGroupName($group)->setRoomName($roomName)->setSubjectName($subject));
    }

    /**
     * Flushes and links the cells to their cards, as an import would.
     */
    private function sync(): void
    {
        $this->em->flush();
        self::getContainer()->get(RoomSynchroniser::class)->sync();
        $this->em->clear();
    }

    /**
     * The codes of a list of rooms, in order.
     *
     * @param list<Room> $rooms the rooms
     *
     * @return list<string> their codes
     */
    private function codes(array $rooms): array
    {
        return array_map(static fn (Room $r): string => $r->getCode(), $rooms);
    }
}
