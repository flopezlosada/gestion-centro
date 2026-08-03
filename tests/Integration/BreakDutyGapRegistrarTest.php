<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakDutyGap;
use App\Entity\BreakZone;
use App\Entity\Notification;
use App\Entity\Role;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\BreakPeriod;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Guardia\AbsenceRegistrar;
use App\Guardia\BreakDutyGapRegistrar;
use App\Tests\Support\OwnsTheBreakZoneCatalogue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What an absence does to a recreo: it is NOT re-covered (the centre has nobody spare at break time),
 * the day is recorded as a gap, and the equipo directivo is alerted to look for a volunteer.
 *
 * The idempotence is the part that earns the gap its own table: an absence is routinely registered more
 * than once for the same day (a couple of periods first, the whole day later), and the leadership must
 * not be alerted twice for the same unwatched zone.
 */
final class BreakDutyGapRegistrarTest extends KernelTestCase
{
    use OwnsTheBreakZoneCatalogue;

    private EntityManagerInterface $em;
    private BreakDutyGapRegistrar $registrar;
    private AbsenceRegistrar $absences;
    private AcademicYear $year;
    private User $onDuty;
    private BreakZone $patio;
    private BreakDutyAssignment $duty;

    /** A Monday inside the 2025-2026 course. */
    private const MONDAY = '2025-11-03';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->registrar = self::getContainer()->get(BreakDutyGapRegistrar::class);
        $this->absences = self::getContainer()->get(AbsenceRegistrar::class);
        $this->emptyTheBreakZoneCatalogue($this->em);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-23'));
        $this->em->persist($this->year);

        $this->patio = (new BreakZone())->setName('Patio')->setWeight(3);
        $this->em->persist($this->patio);

        $this->onDuty = $this->user('Ana Recreo Ruiz', 'ana.recreo@educa.madrid.org');

        // The leadership team: a centre-wide ranked role is what makes someone a recipient of the alert.
        $direction = (new Role())->setCode('direction')->setName('Dirección')->setHierarchyLevel(40);
        $this->em->persist($direction);
        // A ranked-but-per-department role must NOT be alerted: a jefe de departamento commands their
        // department, not the centre.
        $headDept = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
        $this->em->persist($headDept);

        $this->user('Diana Directora Paz', 'diana.direccion@educa.madrid.org')->addAssignedRole($direction);
        $this->user('Jefe Departamento Gil', 'jefe.dept@educa.madrid.org')->addAssignedRole($headDept);

        $this->duty = (new BreakDutyAssignment())
            ->setAcademicYear($this->year)
            ->setTeacher($this->onDuty)
            ->setWeekday(Weekday::MONDAY)
            ->setZone($this->patio)
            ->setPeriod(BreakPeriod::FIRST);
        $this->em->persist($this->duty);

        $this->em->flush();
    }

    public function testRecordsTheGapAndAlertsOnlyTheLeadershipTeam(): void
    {
        $gaps = $this->registrar->register($this->year, $this->onDuty, new \DateTimeImmutable(self::MONDAY));

        // One place, one gap. A teacher holding both recreos of a day would produce two, which is two
        // volunteers to find and not one.
        self::assertCount(1, $gaps);
        $gap = $gaps[0];
        self::assertFalse($gap->isCovered(), 'a fresh gap has no volunteer, which is the normal state');
        self::assertSame($this->duty->getId(), $gap->getAssignment()->getId());

        $alerts = $this->alerts();
        self::assertCount(1, $alerts, 'only the centre-wide leadership is alerted, not the head of department');
        self::assertSame('diana.direccion@educa.madrid.org', $alerts[0]->getRecipient()->getEmail());
        self::assertStringContainsString('Patio', $alerts[0]->getTitle());
    }

    public function testRegisteringTheSameDayTwiceNeitherDuplicatesTheGapNorTheAlert(): void
    {
        $date = new \DateTimeImmutable(self::MONDAY);
        $first = $this->registrar->register($this->year, $this->onDuty, $date);
        $second = $this->registrar->register($this->year, $this->onDuty, $date);

        self::assertSame($first[0]->getId(), $second[0]->getId(), 'the second pass finds the recorded gap');
        self::assertCount(1, $this->em->getRepository(BreakDutyGap::class)->findAll());
        self::assertCount(1, $this->alerts(), 'the equipo directivo is told once per unwatched recreo, not once per edit');
    }

    public function testATeacherWithNoDutyThatWeekdayProducesNothing(): void
    {
        // Same teacher, a Tuesday: their duty is on Mondays.
        $gaps = $this->registrar->register($this->year, $this->onDuty, new \DateTimeImmutable('2025-11-04'));

        self::assertSame([], $gaps);
        self::assertSame([], $this->em->getRepository(BreakDutyGap::class)->findAll());
        self::assertSame([], $this->alerts(), 'nobody is bothered when no recreo is affected');
    }

    public function testRegisteringAnAbsenceCarriesTheRecreoWithoutCoveringIt(): void
    {
        // The teacher also teaches first period, so the absence produces a normal cover AND the gap.
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($this->onDuty)->setWeekday(Weekday::MONDAY)->setSlotIndex(0)
            ->setStartsAt(new \DateTimeImmutable('08:25'))->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(ScheduleActivityKind::LECTIVE)->setGroupName('1ºA')->setRoomName('A10'));
        $this->em->flush();

        $result = $this->absences->register($this->year, $this->onDuty, new \DateTimeImmutable(self::MONDAY), [0], null, [], true);

        self::assertSame(1, $result->createdCount(), 'the taught period still becomes a cover');
        self::assertCount(1, $result->breakGaps, 'the recreo is recorded as a gap');
        self::assertNull($result->breakGaps[0]->getVolunteer(), 'and nobody is assigned to it: it is not re-covered');
        self::assertCount(1, $this->alerts());
    }

    public function testAnAbsenceOnADayWithNoClassesStillReportsTheRecreo(): void
    {
        // No lective cells at all: without the gap this absence would have no visible consequence, and
        // the zone would quietly go unwatched.
        $result = $this->absences->register($this->year, $this->onDuty, new \DateTimeImmutable(self::MONDAY), [], null, [], true);

        self::assertSame(0, $result->createdCount());
        self::assertCount(1, $result->breakGaps);
        self::assertCount(1, $this->alerts());
    }

    public function testNotAskingAboutTheRecreoLeavesItAlone(): void
    {
        // The flag is explicit: a teacher who only misses one class does not lose their recreo.
        $result = $this->absences->register($this->year, $this->onDuty, new \DateTimeImmutable(self::MONDAY), [0], null, [], false);

        self::assertSame([], $result->breakGaps);
        self::assertSame([], $this->em->getRepository(BreakDutyGap::class)->findAll());
    }

    /**
     * The unwatched-recreo notices written so far.
     *
     * @return Notification[] the alerts, in insertion order
     */
    private function alerts(): array
    {
        return $this->em->getRepository(Notification::class)->findBy(['kind' => 'break_duty.uncovered'], ['id' => 'ASC']);
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
}
