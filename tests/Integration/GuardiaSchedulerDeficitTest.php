<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\GuardiaSupport;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Guardia\GuardiaScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What happens when there are more absences than guardia teachers: somebody minds two groups, the
 * hand-added support is used before that, and being absent is still an absolute bar.
 *
 * The counterpart of {@see \App\Tests\Unit\GuardiaAssignerTest}, which pins the ordering rule itself:
 * here it is checked end to end against the database, where hereLoad, the pool and the support
 * arrangements actually come from.
 */
final class GuardiaSchedulerDeficitTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GuardiaScheduler $scheduler;
    private AcademicYear $year;

    /** Feeds unique e-mails for the absent teachers each cover invents; group names are not ASCII-safe. */
    private int $absentees = 0;

    /** A Monday inside the 2025-2026 course. */
    private const MONDAY = '2025-11-03';

    /** The period every absence in these tests falls on. */
    private const SLOT = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->scheduler = self::getContainer()->get(GuardiaScheduler::class);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-23'));
        $this->em->persist($this->year);
    }

    public function testOneGuardiaCoversEveryGroupWhenThereIsNobodyElse(): void
    {
        $only = $this->user('Gonzalo Guardia', 'g1@educa.madrid.org');
        $this->duty($only, ScheduleActivityKind::GUARDIA);
        $covers = [$this->cover('1ºA'), $this->cover('1ºB'), $this->cover('1ºC')];
        $this->em->flush();

        $assigned = $this->scheduler->autoAssign($this->year, new \DateTimeImmutable(self::MONDAY), self::SLOT);

        self::assertSame(3, $assigned, 'no group is left with nobody just because the rota ran out');
        foreach ($covers as $cover) {
            self::assertSame($only->getId(), $cover->getAssignedGuardia()?->getId());
        }
    }

    public function testNobodyIsDoubledUpWhileThereAreEnoughGuardias(): void
    {
        $g1 = $this->user('Ana Guardia', 'a@educa.madrid.org');
        $g2 = $this->user('Bea Guardia', 'b@educa.madrid.org');
        $g3 = $this->user('Carlos Guardia', 'c@educa.madrid.org');
        foreach ([$g1, $g2, $g3] as $teacher) {
            $this->duty($teacher, ScheduleActivityKind::GUARDIA);
        }
        $this->cover('1ºA');
        $this->cover('1ºB');
        $this->cover('1ºC');
        $this->em->flush();

        $this->scheduler->autoAssign($this->year, new \DateTimeImmutable(self::MONDAY), self::SLOT);

        self::assertSame([1, 1, 1], array_values($this->coversPerGuardia()), 'three teachers, three groups, one each');
    }

    public function testSupportIsUsedBeforeDoublingAnybodyUp(): void
    {
        $guardia = $this->user('Ana Guardia', 'a@educa.madrid.org');
        $freed = $this->user('Zoe Liberada', 'z@educa.madrid.org');
        $this->duty($guardia, ScheduleActivityKind::GUARDIA);
        // Signed up by hand for this day and period only. Note the name sorts LAST, so if she is picked
        // it is because of the band order and not the alphabetical tiebreak.
        $this->support($freed);
        $this->cover('1ºA');
        $this->cover('1ºB');
        $this->em->flush();

        $this->scheduler->autoAssign($this->year, new \DateTimeImmutable(self::MONDAY), self::SLOT);

        self::assertSame(
            [1, 1],
            array_values($this->coversPerGuardia()),
            'the freed colleague takes the second group instead of the guardia teacher minding two',
        );
    }

    public function testSupportIsNotUsedWhenTheRotaSuffices(): void
    {
        $guardia = $this->user('Ana Guardia', 'a@educa.madrid.org');
        $freed = $this->user('Zoe Liberada', 'z@educa.madrid.org');
        $this->duty($guardia, ScheduleActivityKind::GUARDIA);
        $this->support($freed);
        $this->cover('1ºA');
        $this->em->flush();

        $this->scheduler->autoAssign($this->year, new \DateTimeImmutable(self::MONDAY), self::SLOT);

        self::assertSame([$guardia->getId() => 1], $this->coversPerGuardia(), 'a favour is not called in while the rota covers it');
    }

    public function testAnAbsentTeacherIsNeverPickedEvenInDeficit(): void
    {
        // The only teacher on guardia duty this period is himself absent then: not a preference, an
        // impossibility, so the groups stay uncovered rather than being handed to a ghost.
        $absentGuardia = $this->user('Gonzalo Guardia', 'g1@educa.madrid.org');
        $this->duty($absentGuardia, ScheduleActivityKind::GUARDIA);
        $this->cover('1ºA', $absentGuardia);
        $this->cover('1ºB');
        $this->em->flush();

        $assigned = $this->scheduler->autoAssign($this->year, new \DateTimeImmutable(self::MONDAY), self::SLOT);

        self::assertSame(0, $assigned);
        self::assertSame([], $this->coversPerGuardia(), 'nobody covers anything');
    }

    public function testExtraGroupsAreSpreadBeforeAnybodyTakesAThird(): void
    {
        $g1 = $this->user('Ana Guardia', 'a@educa.madrid.org');
        $g2 = $this->user('Bea Guardia', 'b@educa.madrid.org');
        $this->duty($g1, ScheduleActivityKind::GUARDIA);
        $this->duty($g2, ScheduleActivityKind::GUARDIA);
        foreach (['1ºA', '1ºB', '1ºC', '1ºD', '1ºE'] as $group) {
            $this->cover($group);
        }
        $this->em->flush();

        $this->scheduler->autoAssign($this->year, new \DateTimeImmutable(self::MONDAY), self::SLOT);

        $perGuardia = array_values($this->coversPerGuardia());
        sort($perGuardia);
        self::assertSame([2, 3], $perGuardia, 'five groups over two teachers, as level as five allows');
    }

    public function testDoublingHappensAcrossSuccessiveAssignmentsToo(): void
    {
        // The realistic path: absences are registered one at a time, so each run sees the previous
        // assignment already in place. The second run must still fall back to the same teacher.
        $only = $this->user('Gonzalo Guardia', 'g1@educa.madrid.org');
        $this->duty($only, ScheduleActivityKind::GUARDIA);
        $this->cover('1ºA');
        $this->em->flush();
        $this->scheduler->autoAssign($this->year, new \DateTimeImmutable(self::MONDAY), self::SLOT);

        $second = $this->cover('1ºB');
        $this->em->flush();
        $this->scheduler->autoAssign($this->year, new \DateTimeImmutable(self::MONDAY), self::SLOT);

        self::assertSame($only->getId(), $second->getAssignedGuardia()?->getId(), 'the second absence doubles him up');
    }

    /**
     * How many covers each guardia teacher ended up with at that period, keyed by teacher id. Read back
     * from the database, so it reflects what was actually persisted.
     *
     * @return array<int, int> teacher id → covers assigned
     */
    private function coversPerGuardia(): array
    {
        $counts = [];
        foreach ($this->em->getRepository(GuardiaCover::class)->findBy(['date' => new \DateTimeImmutable(self::MONDAY), 'slotIndex' => self::SLOT]) as $cover) {
            $teacher = $cover->getAssignedGuardia();
            if (null !== $teacher && null !== $teacher->getId()) {
                $counts[$teacher->getId()] = ($counts[$teacher->getId()] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Persists an uncovered parte line for a group at the test's date and period, with its own absent
     * teacher (a period's covers always come from different people being away).
     *
     * @param string    $group   the group left uncovered
     * @param User|null $teacher the absent teacher, or null to create one for this group
     *
     * @return GuardiaCover the persisted, still unassigned cover
     */
    private function cover(string $group, ?User $teacher = null): GuardiaCover
    {
        $absentTeacher = $teacher ?? $this->user('Falta '.$group, 'falta-'.(++$this->absentees).'@educa.madrid.org');
        $absence = (new Absence())->setAbsentTeacher($absentTeacher)->setDate(new \DateTimeImmutable(self::MONDAY));
        $this->em->persist($absence);

        $cover = (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate(new \DateTimeImmutable(self::MONDAY))
            ->setSlotIndex(self::SLOT)
            ->setAbsentTeacher($absentTeacher)
            ->setGroupName($group)
            ->setRoomName('A10');
        $this->em->persist($cover);

        return $cover;
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
     * Puts a teacher on duty at the test's weekday and period.
     *
     * @param User                 $teacher the teacher
     * @param ScheduleActivityKind $kind    guardia or collaborator
     */
    private function duty(User $teacher, ScheduleActivityKind $kind): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($teacher)->setWeekday(Weekday::MONDAY)->setSlotIndex(self::SLOT)
            ->setStartsAt(new \DateTimeImmutable('08:00'))->setEndsAt(new \DateTimeImmutable('09:00'))
            ->setKind($kind));
    }

    /**
     * Signs a teacher up as one-off support for the test's date and period.
     *
     * @param User $teacher the teacher made available
     */
    private function support(User $teacher): void
    {
        $this->em->persist((new GuardiaSupport())
            ->setTeacher($teacher)
            ->setDate(new \DateTimeImmutable(self::MONDAY))
            ->setSlotIndex(self::SLOT)
            ->setNote('2º de Bach ha terminado las clases.'));
    }
}
