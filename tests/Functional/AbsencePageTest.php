<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\AppSetting;
use App\Entity\Role;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\AbsenceRetention;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Service\AppSettings;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The administrative staff's screen: who is away each day and at which periods, so they can ask for the
 * justificante and record it in RAICES.
 *
 * Gated by its own {@see Area::AUSENCIAS} and not by {@see Area::GUARDIAS}, and that separation is what is
 * tested: whoever reads the absences does not get the parte, and whoever reads the parte is not handed
 * this list for free. And it never shows past the plazo the centre keeps absences for.
 */
final class AbsencePageTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAPlainTeacherCannotSeeWhoIsAway(): void
    {
        $this->loginWith(null);

        $this->client->request('GET', '/ausencias');

        self::assertResponseStatusCodeSame(403);
    }

    public function testReadingThePartesDoesNotOpenTheAbsenceList(): void
    {
        $this->loginWith(Area::GUARDIAS);

        $this->client->request('GET', '/ausencias');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheAbsenceListDoesNotOpenTheParte(): void
    {
        $this->loginWith(Area::AUSENCIAS);

        $crawler = $this->client->request('GET', '/');
        self::assertCount(1, $crawler->filter('#main-nav a[href="/ausencias"]'));
        self::assertCount(0, $crawler->filter('#main-nav a[href="/guardias"]'));

        $this->client->request('GET', '/guardias');
        self::assertResponseStatusCodeSame(403);
    }

    public function testListsTheTeacherDayAndPeriodsOfEachAbsenceKept(): void
    {
        $year = $this->currentYear();
        $teacher = $this->teacher('Ana Ausente');
        $this->period($year, $teacher, 0, '08:15', '09:10');
        $this->period($year, $teacher, 1, '09:10', '10:05');
        $this->absence($teacher, new \DateTimeImmutable('today'), [0, 1]);
        $this->loginWith(Area::AUSENCIAS);

        $crawler = $this->client->request('GET', '/ausencias');

        self::assertResponseIsSuccessful();
        $cells = $crawler->filter('tbody tr')->first()->filter('td');
        self::assertSame((new \DateTimeImmutable('today'))->format('d/m/Y'), trim($cells->eq(0)->text()));
        self::assertSame('Ana Ausente', trim($cells->eq(1)->text()));
        self::assertSame('08:15–09:10, 09:10–10:05', trim($cells->eq(2)->text()));
    }

    public function testShowsNothingOlderThanThePlazoEvenBeforeThePurgeRuns(): void
    {
        $keptFrom = AbsenceRetention::DEFAULT->keptFrom(new \DateTimeImmutable('today'));
        $this->absence($this->teacher('Dentro del plazo'), $keptFrom, [2]);
        $this->absence($this->teacher('Fuera del plazo'), $keptFrom->modify('-1 day'), [2]);
        $this->loginWith(Area::AUSENCIAS);

        $crawler = $this->client->request('GET', '/ausencias');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Dentro del plazo', $crawler->filter('tbody')->text());
        self::assertStringNotContainsString('Fuera del plazo', $crawler->filter('tbody')->text());
        self::assertStringContainsString(AbsenceRetention::DEFAULT->label(), $crawler->filter('.help')->text());
    }

    public function testFollowsThePlazoTheCentrePicked(): void
    {
        $this->em->persist(new AppSetting(AppSettings::ABSENCE_RETENTION_WEEKS, (string) AbsenceRetention::ONE_WEEK->value));
        $this->absence($this->teacher('Hace tres días'), new \DateTimeImmutable('-3 days'), [2]);
        $this->absence($this->teacher('Hace diez días'), new \DateTimeImmutable('-10 days'), [2]);
        $this->loginWith(Area::AUSENCIAS);

        $crawler = $this->client->request('GET', '/ausencias');

        self::assertStringContainsString('Hace tres días', $crawler->filter('tbody')->text());
        self::assertStringNotContainsString('Hace diez días', $crawler->filter('tbody')->text());
        self::assertStringContainsString('1 semana', $crawler->filter('.help')->text());
    }

    public function testEachDayTakesThePeriodTimesOfItsOwnCourse(): void
    {
        // Late September looks back into June: two courses, and their timetables need not agree.
        self::mockTime('2026-09-10 10:00');
        $teacher = $this->teacher('Entre dos cursos');
        $this->period($this->year('2025-2026'), $teacher, 0, '08:30', '09:25');
        $this->period($this->year('2026-2027'), $teacher, 0, '08:15', '09:10');
        $this->absence($teacher, new \DateTimeImmutable('2026-09-08'), [0]);
        $this->absence($teacher, new \DateTimeImmutable('2026-08-28'), [0]);
        $this->loginWith(Area::AUSENCIAS);

        $crawler = $this->client->request('GET', '/ausencias');

        $rows = $crawler->filter('tbody tr');
        self::assertCount(2, $rows);
        self::assertSame(['08/09/2026', '08:15–09:10'], [trim($rows->eq(0)->filter('td')->eq(0)->text()), trim($rows->eq(0)->filter('td')->eq(2)->text())]);
        self::assertSame(['28/08/2026', '08:30–09:25'], [trim($rows->eq(1)->filter('td')->eq(0)->text()), trim($rows->eq(1)->filter('td')->eq(2)->text())]);
    }

    public function testAPeriodWithoutAKnownTimeIsShownByItsOrdinal(): void
    {
        // No timetable for the course: the times are unknown, and the period is still worth telling.
        $this->absence($this->teacher('Sin horario'), new \DateTimeImmutable('today'), [2]);
        $this->loginWith(Area::AUSENCIAS);

        $crawler = $this->client->request('GET', '/ausencias');

        self::assertSame('3ª', trim($crawler->filter('tbody tr td')->eq(2)->text()));
    }

    public function testWithNoAbsencesSaysSoInsteadOfAnEmptyTable(): void
    {
        $this->loginWith(Area::AUSENCIAS);

        $crawler = $this->client->request('GET', '/ausencias');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('table'));
        self::assertCount(1, $crawler->filter('.empty-state'));
    }

    /**
     * Logs in somebody with READ on a single area, or a plain teacher.
     *
     * @param Area|null $area the area to read, or null for no permission at all
     */
    private function loginWith(?Area $area): void
    {
        $user = (new User())->setFullName('Quien mira')->setEmail('mira-'.uniqid().'@centro.test');
        if (null !== $area) {
            $role = (new Role())->setCode('rol-'.uniqid())->setName('Rol de prueba')->setLevel($area, PermissionLevel::READ);
            $this->em->persist($role);
            $user->addAssignedRole($role);
        }
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }

    /**
     * Persists a teacher.
     *
     * @param string $name the full name, used to spot the row
     *
     * @return User the persisted teacher
     */
    private function teacher(string $name): User
    {
        $teacher = (new User())->setFullName($name)->setEmail('t-'.uniqid().'@centro.test');
        $this->em->persist($teacher);

        return $teacher;
    }

    /**
     * Persists an absence and flushes, so the page reads it.
     *
     * @param User               $teacher the absent teacher
     * @param \DateTimeImmutable $date    the day
     * @param list<int>          $slots   the period indexes it spans
     */
    private function absence(User $teacher, \DateTimeImmutable $date, array $slots): void
    {
        $this->em->persist((new Absence())->setAbsentTeacher($teacher)->setDate($date)->addSlotIndexes($slots));
        $this->em->flush();
    }

    /**
     * Gives the course a period at a given time, which is where the page reads the times from.
     */
    private function period(AcademicYear $year, User $teacher, int $slotIndex, string $startsAt, string $endsAt): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($year)->setTeacher($teacher)->setWeekday(Weekday::MONDAY)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable($startsAt))->setEndsAt(new \DateTimeImmutable($endsAt))
            ->setKind(ScheduleActivityKind::LECTIVE)->setGroupName('1ºA')->setRoomName('A10')->setSubjectName('Materia'));
    }

    /**
     * Persists the course today falls into.
     *
     * @return AcademicYear the persisted course
     */
    private function currentYear(): AcademicYear
    {
        return $this->year(SchoolYear::current(new \DateTimeImmutable('today')));
    }

    /**
     * Persists a course.
     *
     * @param string $schoolYear the course, as "2025-2026"
     *
     * @return AcademicYear the persisted course
     */
    private function year(string $schoolYear): AcademicYear
    {
        $start = (int) substr($schoolYear, 0, 4);
        $year = (new AcademicYear())
            ->setSchoolYear($schoolYear)
            ->setTerm1Start(new \DateTimeImmutable($start.'-09-15'))
            ->setTerm1End(new \DateTimeImmutable($start.'-12-22'))
            ->setTerm2Start(new \DateTimeImmutable(($start + 1).'-01-08'))
            ->setTerm2End(new \DateTimeImmutable(($start + 1).'-03-27'))
            ->setTerm3Start(new \DateTimeImmutable(($start + 1).'-04-07'))
            ->setTerm3End(new \DateTimeImmutable(($start + 1).'-06-22'));
        $this->em->persist($year);

        return $year;
    }
}
