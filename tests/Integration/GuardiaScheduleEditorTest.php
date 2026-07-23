<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Guardia\GuardiaScheduleEditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The manual guardia editor stamps duty cells for a teacher from the posted grid without ever touching
 * the imported lessons: it only writes free periods that map to the marco horario, replaces just that
 * teacher's previous duty cells on re-save, and its grid reflects both lessons and marked duties.
 */
final class GuardiaScheduleEditorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GuardiaScheduleEditor $editor;
    private AcademicYear $year;
    private User $teacher;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->editor = self::getContainer()->get(GuardiaScheduleEditor::class);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-23'));
        $this->em->persist($this->year);

        // A filler teacher establishes the marco horario: periods 0, 1 and 2 with their times, exactly
        // as an import would (distinctSlots derives the periods from the existing cells).
        $filler = $this->user('Marco Horario', 'marco@educa.madrid.org');
        $this->lective($filler, Weekday::MONDAY, 0, '08:00', '09:00');
        $this->lective($filler, Weekday::MONDAY, 1, '09:00', '10:00');
        $this->lective($filler, Weekday::MONDAY, 2, '10:00', '11:00');

        // The teacher we edit teaches Monday period 0, so that cell can never be a guardia.
        $this->teacher = $this->user('Elena Edita Ríos', 'elena@educa.madrid.org');
        $this->lective($this->teacher, Weekday::MONDAY, 0, '08:00', '09:00');

        $this->em->flush();
    }

    public function testSavesDutyCellsOnFreePeriodsAndNeverOverwritesLessons(): void
    {
        $count = $this->editor->save($this->year, $this->teacher, [
            (string) Weekday::MONDAY->value => [0 => 'guardia', 1 => 'guardia'],   // slot 0 is a lesson → ignored
            (string) Weekday::TUESDAY->value => [2 => 'collaborator'],
        ]);

        self::assertSame(2, $count, 'only the two free, valid cells are stamped (the lesson slot is refused)');

        $entries = $this->entriesFor($this->teacher);
        // The imported lesson survives untouched.
        self::assertNotNull($this->pick($entries, Weekday::MONDAY, 0, ScheduleActivityKind::LECTIVE));
        // The free cells become duty entries with the marco horario's times.
        $mon1 = $this->pick($entries, Weekday::MONDAY, 1, ScheduleActivityKind::GUARDIA);
        self::assertNotNull($mon1);
        self::assertSame('09:00', $mon1->getStartsAt()->format('H:i'), 'time taken from the marco horario');
        self::assertSame('10:00', $mon1->getEndsAt()->format('H:i'));
        self::assertNotNull($this->pick($entries, Weekday::TUESDAY, 2, ScheduleActivityKind::COLLABORATOR));
        // Nothing was written on the refused lesson slot as a guardia.
        self::assertNull($this->pick($entries, Weekday::MONDAY, 0, ScheduleActivityKind::GUARDIA));
    }

    public function testResavingReplacesOnlyThePreviousDutyCells(): void
    {
        $this->editor->save($this->year, $this->teacher, [
            (string) Weekday::MONDAY->value => [1 => 'guardia'],
            (string) Weekday::TUESDAY->value => [2 => 'collaborator'],
        ]);

        // A second save with a different set drops the old duty cells and keeps the lesson.
        $count = $this->editor->save($this->year, $this->teacher, [
            (string) Weekday::WEDNESDAY->value => [1 => 'guardia'],
        ]);

        self::assertSame(1, $count);
        $entries = $this->entriesFor($this->teacher);
        self::assertNull($this->pick($entries, Weekday::MONDAY, 1, ScheduleActivityKind::GUARDIA), 'old duty cell gone');
        self::assertNull($this->pick($entries, Weekday::TUESDAY, 2, ScheduleActivityKind::COLLABORATOR), 'old duty cell gone');
        self::assertNotNull($this->pick($entries, Weekday::WEDNESDAY, 1, ScheduleActivityKind::GUARDIA), 'new duty cell present');
        self::assertNotNull($this->pick($entries, Weekday::MONDAY, 0, ScheduleActivityKind::LECTIVE), 'lesson untouched by re-save');
    }

    public function testIgnoresCellsOutsideTheMarcoHorario(): void
    {
        // Period 7 has no imported cell, so no known times: it cannot be stamped.
        $count = $this->editor->save($this->year, $this->teacher, [
            (string) Weekday::TUESDAY->value => [7 => 'guardia'],
        ]);

        self::assertSame(0, $count);
    }

    public function testGridShowsLessonsReadOnlyAndPreselectsMarkedDuties(): void
    {
        $this->editor->save($this->year, $this->teacher, [
            (string) Weekday::TUESDAY->value => [1 => 'guardia'],
        ]);

        $grid = $this->editor->grid($this->year, $this->teacher);

        self::assertSame([0, 1, 2], array_column($grid['slots'], 'index'), 'columns are the imported periods');
        self::assertTrue($grid['cells'][Weekday::MONDAY->value][0]['lective'], 'the lesson cell is read-only');
        self::assertSame('guardia', $grid['cells'][Weekday::TUESDAY->value][1]['value'], 'the marked duty cell is preselected');
        self::assertSame('', $grid['cells'][Weekday::MONDAY->value][1]['value'], 'an unmarked free cell is empty');
    }

    /**
     * The teacher's timetable cells in the test course.
     *
     * @param User $teacher the teacher
     *
     * @return ScheduleEntry[] the cells
     */
    private function entriesFor(User $teacher): array
    {
        $teacherId = $teacher->getId();
        $yearId = $this->year->getId();
        $this->em->clear();

        return $this->em->getRepository(ScheduleEntry::class)->findBy([
            'academicYear' => $this->em->find(AcademicYear::class, $yearId),
            'teacher' => $this->em->find(User::class, $teacherId),
        ]);
    }

    /**
     * Finds the single cell matching weekday, period and kind, or null.
     *
     * @param ScheduleEntry[]      $entries   the cells to search
     * @param Weekday              $weekday   the weekday
     * @param int                  $slotIndex the period index
     * @param ScheduleActivityKind $kind      the kind
     *
     * @return ScheduleEntry|null the match, or null
     */
    private function pick(array $entries, Weekday $weekday, int $slotIndex, ScheduleActivityKind $kind): ?ScheduleEntry
    {
        foreach ($entries as $entry) {
            if ($entry->getWeekday() === $weekday && $entry->getSlotIndex() === $slotIndex && $entry->getKind() === $kind) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Persists a user with a name and e-mail.
     *
     * @param string $name  the full name
     * @param string $email the e-mail
     *
     * @return User the persisted user
     */
    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /**
     * Persists a lective cell for a teacher at a weekday and period.
     *
     * @param User    $teacher   the teacher
     * @param Weekday $weekday   the weekday
     * @param int     $slotIndex the period index
     * @param string  $startsAt  the period start time ("H:i")
     * @param string  $endsAt    the period end time ("H:i")
     */
    private function lective(User $teacher, Weekday $weekday, int $slotIndex, string $startsAt, string $endsAt): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($teacher)->setWeekday($weekday)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable($startsAt))->setEndsAt(new \DateTimeImmutable($endsAt))
            ->setKind(ScheduleActivityKind::LECTIVE)->setGroupName('1ºA')->setRoomName('A10')->setSubjectName('Materia'));
    }
}
