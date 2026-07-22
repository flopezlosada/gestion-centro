<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AcademicYear;
use App\Entity\Role;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The parte de guardias reads the timetable of the course the queried date falls into: for a date with
 * no imported course it shows the empty state naming that course, and for one with an imported
 * timetable it shows the period tabs.
 *
 * Access is gated by the {@see Area::GUARDIAS} matrix: the management screens (parte, history, stats)
 * need read access to the area — a plain teacher without it is denied.
 */
final class GuardiaPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Logs in a user, optionally as a guardia coordinator (a role granting write on the Guardias area).
     *
     * @param bool $coordinator whether to grant the guardia-coordinator role
     */
    private function login(bool $coordinator = true): User
    {
        $user = (new User())->setFullName('Docente Test')->setEmail('profe@centro.test');
        if ($coordinator) {
            $role = (new Role())->setCode('guardias')->setName('Coordinación de guardias')->setLevel(Area::GUARDIAS, PermissionLevel::WRITE);
            $this->em->persist($role);
            $user->addAssignedRole($role);
        }
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

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

    public function testEmptyStateNamesTheCourseWhenNoTimetableImported(): void
    {
        $this->login();

        // A far-future date: no course structure exists for 2098-2099, so the empty state must show.
        $this->client->request('GET', '/guardias?date=2099-01-15');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state', 'No hay horario importado para el curso 2098-2099');
    }

    public function testShowsPeriodTabsWhenTimetableImported(): void
    {
        $this->login();

        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        $teacher = (new User())->setFullName('Guardia Docente')->setEmail('guardia@centro.test');
        $this->em->persist($teacher);

        $date = new \DateTimeImmutable('2025-11-10');
        $this->em->persist(
            (new ScheduleEntry())
                ->setAcademicYear($year)
                ->setTeacher($teacher)
                ->setWeekday(Weekday::from((int) $date->format('N')))
                ->setSlotIndex(0)
                ->setStartsAt(new \DateTimeImmutable('08:25'))
                ->setEndsAt(new \DateTimeImmutable('09:20'))
                ->setKind(ScheduleActivityKind::GUARDIA)
        );
        $this->em->flush();

        $this->client->request('GET', '/guardias?date='.$date->format('Y-m-d'));

        self::assertResponseIsSuccessful();
        // Target the period tabs specifically: the page also has a management sub-nav using .tabs.
        self::assertSelectorTextContains('nav[aria-label="Tramo horario"]', '08:25');
        self::assertSelectorNotExists('.empty-state');
    }

    public function testPlainTeacherCannotAccessManagementScreens(): void
    {
        $this->login(coordinator: false);

        foreach (['/guardias', '/guardias/historico', '/guardias/estadisticas'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(403, sprintf('%s must be denied to a non-coordinator', $path));
        }
    }

    public function testCoordinatorReachesHistoryAndStats(): void
    {
        $this->login();

        $this->client->request('GET', '/guardias/historico');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/guardias/estadisticas');
        self::assertResponseIsSuccessful();
    }

    public function testMisGuardiasIsOpenToAnyTeacher(): void
    {
        $this->login(coordinator: false);

        $this->client->request('GET', '/guardias/mias');
        self::assertResponseIsSuccessful();
    }
}
