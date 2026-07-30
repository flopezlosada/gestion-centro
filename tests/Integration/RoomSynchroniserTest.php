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
use App\Repository\RoomRepository;
use App\Space\RoomSynchroniser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The synchroniser gives every room the timetable names a card and links every cell to it, without ever
 * touching what a person has filled in: it creates and links, and that is all. Two properties matter
 * beyond the happy path — it is idempotent (an import runs it every time) and it folds the spellings of
 * one room into a single card.
 */
final class RoomSynchroniserTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RoomSynchroniser $synchroniser;
    private RoomRepository $rooms;
    private AcademicYear $year;
    private User $teacher;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->synchroniser = self::getContainer()->get(RoomSynchroniser::class);
        $this->rooms = self::getContainer()->get(RoomRepository::class);

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

    public function testCreatesAStubCardForEveryRoomTheTimetableNames(): void
    {
        $this->lective(Weekday::MONDAY, 0, '2IN5');
        $this->lective(Weekday::MONDAY, 1, 'S ACTOS');
        $this->em->flush();

        $result = $this->synchroniser->sync();

        self::assertSame(['2IN5', 'S ACTOS'], $result->createdCodes);
        self::assertSame(2, $result->linkedCells);

        $card = $this->rooms->findByCode('2IN5');
        self::assertNotNull($card);
        self::assertSame('2IN5', $card->getName(), 'the name starts as the code: only a person can name it');
        self::assertSame(RoomKind::OTHER, $card->getKind());
        self::assertNull($card->getCapacity(), 'capacity is never invented');
        self::assertTrue($card->needsReview());
    }

    public function testLinksEveryCellToItsCard(): void
    {
        $this->lective(Weekday::MONDAY, 0, '2IN5');
        $this->lective(Weekday::TUESDAY, 0, '2IN5');
        $this->em->flush();

        $this->synchroniser->sync();
        $this->em->clear();

        foreach ($this->cells() as $cell) {
            self::assertNotNull($cell->getRoom(), 'a cell naming a room must point at its card');
            self::assertSame('2IN5', $cell->getRoom()->getCode());
        }
        self::assertSame(0, $this->synchroniser->unlinkedCells());
    }

    public function testDifferentSpellingsOfOneRoomShareASingleCard(): void
    {
        // Peñalara is not always tidy, and a hand-edited cell even less so.
        $this->lective(Weekday::MONDAY, 0, 'S ACTOS');
        $this->lective(Weekday::TUESDAY, 0, 's  actos');
        $this->em->flush();

        $result = $this->synchroniser->sync();

        self::assertSame(['S ACTOS'], $result->createdCodes, 'one room, one card');
        self::assertSame(2, $result->linkedCells, 'both spellings link to it');
    }

    public function testIsIdempotent(): void
    {
        $this->lective(Weekday::MONDAY, 0, '2IN5');
        $this->em->flush();

        $this->synchroniser->sync();
        $second = $this->synchroniser->sync();

        self::assertTrue($second->isEmpty(), 'a re-import must not create or relink anything');
    }

    public function testNeverOverwritesWhatAPersonFilledIn(): void
    {
        $card = (new Room())->setCode('2IN5')->setName('Aula de Inglés 5')->setKind(RoomKind::CLASSROOM)->setCapacity(28);
        $this->em->persist($card);
        $this->lective(Weekday::MONDAY, 0, '2in5');
        $this->em->flush();

        $result = $this->synchroniser->sync();
        $this->em->clear();

        self::assertSame([], $result->createdCodes, 'the card already existed, whatever the cell spelled');
        $kept = $this->rooms->findByCode('2IN5');
        self::assertNotNull($kept);
        self::assertSame('Aula de Inglés 5', $kept->getName());
        self::assertSame(28, $kept->getCapacity());
        self::assertNull($kept->getSize(), 'the size the centre has NOT set is not set by the sync either');
    }

    public function testWritesDownHowManyGroupsTheTimetableFitsInARoom(): void
    {
        // Two groups in the assembly hall at the same time is the evidence that it holds two; the ordinary
        // classroom next door never holds more than one, and that proves nothing about a second.
        $this->lective(Weekday::MONDAY, 0, 'S ACTOS', 'E4A');
        $this->lective(Weekday::MONDAY, 0, 'S ACTOS', 'E4B');
        $this->lective(Weekday::MONDAY, 0, '2IN5', 'E1A');
        $this->em->flush();

        $result = $this->synchroniser->sync();
        $this->em->clear();

        self::assertSame(2, $result->resizedRooms, 'both cards learned their size from the timetable');
        $hall = $this->rooms->findByCode('S ACTOS');
        self::assertNotNull($hall);
        self::assertSame(2, $hall->getObservedGroups());
        self::assertSame(RoomSize::TWO_GROUPS, $hall->effectiveSize());
        self::assertFalse($hall->isSizeConfirmed(), 'evidence is not a person saying so');
        self::assertTrue($hall->needsReview(), 'and the card still asks to be completed');

        $classroom = $this->rooms->findByCode('2IN5');
        self::assertNotNull($classroom);
        self::assertSame(RoomSize::ONE_GROUP, $classroom->effectiveSize());
    }

    public function testASizeTheCentreConfirmedWinsOverTheTimetableEvidence(): void
    {
        // A room that has held two groups can still be classified as one-group ("we did it once and it was
        // a squeeze"). The person's answer is the one the engine must obey.
        $card = (new Room())->setCode('S ACTOS')->setName('Salón de actos')->setSize(RoomSize::ONE_GROUP);
        $this->em->persist($card);
        $this->lective(Weekday::MONDAY, 0, 'S ACTOS', 'E4A');
        $this->lective(Weekday::MONDAY, 0, 'S ACTOS', 'E4B');
        $this->em->flush();

        $this->synchroniser->sync();
        $this->em->clear();

        $hall = $this->rooms->findByCode('S ACTOS');
        self::assertNotNull($hall);
        self::assertSame(2, $hall->getObservedGroups(), 'the evidence is still recorded');
        self::assertSame(RoomSize::ONE_GROUP, $hall->effectiveSize(), 'but it does not override the centre');
        self::assertTrue($hall->isSizeConfirmed());
    }

    public function testARoomThatLeavesTheTimetableLosesItsObservedSize(): void
    {
        // Evidence has to be able to go away: keeping "two groups fit" from a timetable that no longer
        // says so would be exactly the stale figure this replaces.
        $this->lective(Weekday::MONDAY, 0, 'S ACTOS', 'E4A');
        $this->lective(Weekday::MONDAY, 0, 'S ACTOS', 'E4B');
        $this->em->flush();
        $this->synchroniser->sync();

        foreach ($this->cells() as $cell) {
            $this->em->remove($cell);
        }
        $this->em->flush();
        $result = $this->synchroniser->sync();
        $this->em->clear();

        self::assertSame(1, $result->resizedRooms);
        $hall = $this->rooms->findByCode('S ACTOS');
        self::assertNotNull($hall);
        self::assertNull($hall->getObservedGroups());
        self::assertNull($hall->effectiveSize(), 'no evidence and no answer means no size, not a guess');
    }

    public function testDutyCellsHaveNoRoomAndAreNotCountedAsUnlinked(): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($this->teacher)
            ->setWeekday(Weekday::MONDAY)->setSlotIndex(0)
            ->setStartsAt(new \DateTimeImmutable('08:00'))->setEndsAt(new \DateTimeImmutable('09:00'))
            ->setKind(ScheduleActivityKind::GUARDIA));
        $this->em->flush();

        $result = $this->synchroniser->sync();

        self::assertTrue($result->isEmpty(), 'a guardia occupies no room');
        self::assertSame(0, $this->synchroniser->unlinkedCells());
    }

    /**
     * Persists a lective cell in the test course, in the given room.
     *
     * @param Weekday $weekday   the weekday
     * @param int     $slotIndex the period index
     * @param string  $roomName  the room name exactly as the timetable spells it
     * @param string  $group     the group in the room, which is what the observed size counts
     */
    private function lective(Weekday $weekday, int $slotIndex, string $roomName, string $group = 'E1A'): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($this->teacher)
            ->setWeekday($weekday)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:00'))->setEndsAt(new \DateTimeImmutable('09:00'))
            ->setKind(ScheduleActivityKind::LECTIVE)
            ->setGroupName($group)->setRoomName($roomName)->setSubjectName('Inglés'));
    }

    /**
     * The test course's cells, re-read from the database.
     *
     * @return ScheduleEntry[] the cells
     */
    private function cells(): array
    {
        return $this->em->getRepository(ScheduleEntry::class)->findAll();
    }
}
