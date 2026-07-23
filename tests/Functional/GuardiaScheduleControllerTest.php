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
use Symfony\Component\DomCrawler\Crawler;

/**
 * The manual "horario de guardias" screen is a coordinator surface gated by write on the
 * {@see Area::GUARDIAS} matrix: a plain teacher is denied on both the GET and the POST. A coordinator
 * marks a teacher's guardia cells and, on a valid submit, they are persisted; a bad CSRF token is
 * refused.
 */
final class GuardiaScheduleControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testPlainTeacherIsDeniedOnGetAndPost(): void
    {
        $this->login(coordinator: false);

        $this->client->request('GET', '/guardias/horario');
        self::assertResponseStatusCodeSame(403, 'the manual editor must be denied to a non-coordinator');

        $this->client->request('POST', '/guardias/horario', ['_token' => 'whatever']);
        self::assertResponseStatusCodeSame(403, 'saving must be denied to a non-coordinator');
    }

    public function testCoordinatorSeesTheGridForATeacherWithAnImportedTimetable(): void
    {
        $this->login();
        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        // A filler lesson establishes the marco horario (the grid columns) for the course.
        $this->lective($year, $this->user('Marco Filler', 'filler@centro.test'), Weekday::MONDAY, 0);
        $teacher = $this->user('Elena Edita', 'elena@centro.test');
        $this->em->flush();

        $this->client->request('GET', '/guardias/horario?curso=2025-2026&teacher='.$teacher->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table.tt-grid');
        self::assertSelectorExists('select[name="cell[1][0]"]', 'the Monday first-period cell is an editable select');
    }

    public function testSavingPersistsTheMarkedGuardiaCell(): void
    {
        $this->login();
        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        $this->lective($year, $this->user('Marco Filler', 'filler@centro.test'), Weekday::MONDAY, 0);
        $teacher = $this->user('Elena Edita', 'elena@centro.test');
        $this->em->flush();
        $teacherId = (int) $teacher->getId();

        // Render the form to get a valid CSRF token, then post a guardia on Monday's first period.
        $crawler = $this->client->request('GET', '/guardias/horario?curso=2025-2026&teacher='.$teacherId);
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/guardias/horario', [
            '_token' => $this->saveToken($crawler),
            'curso' => '2025-2026',
            'teacher' => (string) $teacherId,
            'cell' => [(string) Weekday::MONDAY->value => [0 => 'guardia']],
        ]);
        self::assertResponseRedirects();

        $this->em->clear();
        $entries = $this->em->getRepository(ScheduleEntry::class)->findBy([
            'teacher' => $this->em->find(User::class, $teacherId),
            'kind' => ScheduleActivityKind::GUARDIA,
        ]);
        self::assertCount(1, $entries, 'the marked cell became a guardia entry');
        self::assertSame(Weekday::MONDAY, $entries[0]->getWeekday());
        self::assertSame(0, $entries[0]->getSlotIndex());
        self::assertSame('08:25', $entries[0]->getStartsAt()->format('H:i'), 'time taken from the marco horario');
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $this->login();
        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        $this->lective($year, $this->user('Marco Filler', 'filler@centro.test'), Weekday::MONDAY, 0);
        $teacher = $this->user('Elena Edita', 'elena@centro.test');
        $this->em->flush();
        $teacherId = (int) $teacher->getId();

        $this->client->request('POST', '/guardias/horario', [
            '_token' => 'wrong',
            'curso' => '2025-2026',
            'teacher' => (string) $teacherId,
            'cell' => [(string) Weekday::MONDAY->value => [0 => 'guardia']],
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame(0, (int) $this->em->getRepository(ScheduleEntry::class)->count([
            'teacher' => $this->em->find(User::class, $teacherId),
            'kind' => ScheduleActivityKind::GUARDIA,
        ]), 'nothing is written when the token is invalid');
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

    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /**
     * Persists a lective cell, standing in for what the Peñalara import would create — it fixes the
     * course's marco horario (period 0 at 08:25–09:20) that the grid reads its columns and times from.
     */
    private function lective(AcademicYear $year, User $teacher, Weekday $weekday, int $slotIndex): void
    {
        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($year)->setTeacher($teacher)->setWeekday($weekday)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:25'))->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(ScheduleActivityKind::LECTIVE)->setGroupName('1ºA')->setRoomName('A10')->setSubjectName('Materia'));
    }

    /**
     * Reads the CSRF token the rendered save form carries, so a follow-up POST is valid in the session.
     */
    private function saveToken(Crawler $crawler): string
    {
        return (string) $crawler->filter('form[action="/guardias/horario"] input[name="_token"]')->attr('value');
    }
}
