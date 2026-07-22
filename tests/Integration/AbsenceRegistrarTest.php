<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Guardia\AbsenceRegistrar;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Registering an absence turns each period the teacher actually teaches into a cover and runs the
 * equitable assignment; free periods and already-registered ones are skipped, not covered.
 */
final class AbsenceRegistrarTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AbsenceRegistrar $registrar;
    private AcademicYear $year;
    private User $absent;
    private User $g1;
    private User $g2;

    /** A Monday inside the 2025-2026 course. */
    private const MONDAY = '2025-11-03';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->registrar = self::getContainer()->get(AbsenceRegistrar::class);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-23'));
        $this->em->persist($this->year);

        $this->absent = $this->user('Ana Ausente Ruiz', 'ana.ausente@educa.madrid.org');
        $this->g1 = $this->user('Gonzalo Guardia Uno', 'g1@educa.madrid.org');
        $this->g2 = $this->user('Gema Guardia Dos', 'g2@educa.madrid.org');

        // Absent teacher: teaches slots 0 and 2 on Monday, free at slot 1.
        $this->lective($this->absent, 0, '1ºA', 'A10');
        $this->lective($this->absent, 2, '2ºB', 'A12');
        // One guardia teacher on call at each of those periods.
        $this->duty($this->g1, 0, ScheduleActivityKind::GUARDIA);
        $this->duty($this->g2, 2, ScheduleActivityKind::GUARDIA);

        $this->em->flush();
    }

    public function testWholeDayCreatesACoverPerTaughtPeriodAndAssignsThem(): void
    {
        $result = $this->registrar->register($this->year, $this->absent, new \DateTimeImmutable(self::MONDAY), null, 'Ejercicios pág. 42');

        self::assertSame(2, $result->createdCount(), 'only the two taught periods become covers');
        self::assertSame(0, $result->skippedFree, 'whole-day mode never even considers free periods');

        $covers = $this->coversFor($this->absent);
        self::assertCount(2, $covers);
        self::assertSame($this->g1->getId(), $covers[0]->getAssignedGuardia()?->getId(), 'slot 0 covered by the guardia on call then');
        self::assertSame($this->g2->getId(), $covers[2]->getAssignedGuardia()?->getId(), 'slot 2 covered by its guardia');
        self::assertSame('1ºA', $covers[0]->getGroupName(), 'group snapshotted from the timetable');
    }

    public function testSpecificPeriodsSkipsTheFreeOne(): void
    {
        $result = $this->registrar->register($this->year, $this->absent, new \DateTimeImmutable(self::MONDAY), [0, 1, 2], null);

        self::assertSame(2, $result->createdCount());
        self::assertSame(1, $result->skippedFree, 'slot 1 has no class, so it is skipped');
    }

    public function testDoesNotDuplicateAnAlreadyRegisteredPeriod(): void
    {
        $date = new \DateTimeImmutable(self::MONDAY);
        $this->registrar->register($this->year, $this->absent, $date, [0], null);
        $result = $this->registrar->register($this->year, $this->absent, $date, [0], null);

        self::assertSame(0, $result->createdCount());
        self::assertSame(1, $result->skippedExisting);
        self::assertCount(1, $this->coversFor($this->absent), 'still a single cover for that period');
    }

    /**
     * The absent teacher's covers keyed by period index.
     *
     * @param User $teacher the absent teacher
     *
     * @return array<int, GuardiaCover> covers by slot index
     */
    private function coversFor(User $teacher): array
    {
        $covers = [];
        foreach ($this->em->getRepository(GuardiaCover::class)->findBy(['absentTeacher' => $teacher]) as $cover) {
            $covers[$cover->getSlotIndex()] = $cover;
        }

        return $covers;
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
     * Persists a Monday lective cell for a teacher at a period.
     *
     * @param User   $teacher   the teacher
     * @param int    $slotIndex the period index
     * @param string $group     the group short name
     * @param string $room      the room short name
     */
    private function lective(User $teacher, int $slotIndex, string $group, string $room): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($teacher)->setWeekday(Weekday::MONDAY)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:00'))->setEndsAt(new \DateTimeImmutable('09:00'))
            ->setKind(ScheduleActivityKind::LECTIVE)->setGroupName($group)->setRoomName($room)->setSubjectName('Materia'));
    }

    /**
     * Persists a Monday duty (guardia/collaborator) cell for a teacher at a period.
     *
     * @param User                 $teacher   the teacher
     * @param int                  $slotIndex the period index
     * @param ScheduleActivityKind $kind      guardia or collaborator
     */
    private function duty(User $teacher, int $slotIndex, ScheduleActivityKind $kind): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($teacher)->setWeekday(Weekday::MONDAY)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:00'))->setEndsAt(new \DateTimeImmutable('09:00'))
            ->setKind($kind));
    }
}
