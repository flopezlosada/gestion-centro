<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\Role;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

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

    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /**
     * A guardia slot in the timetable, so the parte page has a period to show and a pool to draw from
     * (the on-call teacher for that weekday/period).
     */
    private function guardiaEntry(AcademicYear $year, User $teacher, \DateTimeImmutable $date, int $slot = 0): ScheduleEntry
    {
        $entry = (new ScheduleEntry())
            ->setAcademicYear($year)
            ->setTeacher($teacher)
            ->setWeekday(Weekday::from((int) $date->format('N')))
            ->setSlotIndex($slot)
            ->setStartsAt(new \DateTimeImmutable('08:25'))
            ->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(ScheduleActivityKind::GUARDIA);
        $this->em->persist($entry);

        return $entry;
    }

    /** @var array<string, Absence> one absence per (absent teacher, day), reused across its periods */
    private array $absences = [];

    private function cover(\DateTimeImmutable $date, int $slot, User $absent, ?User $assigned = null, bool $notCovered = false, string $group = '1ºA'): GuardiaCover
    {
        // The day's periods for one teacher share a single absence (its private reason lives there),
        // matching the unique (absent teacher, day) constraint.
        $key = spl_object_id($absent).'|'.$date->format('Y-m-d');
        $absence = $this->absences[$key] ?? null;
        if (null === $absence) {
            $absence = (new Absence())->setAbsentTeacher($absent)->setDate($date);
            $this->em->persist($absence);
            $this->absences[$key] = $absence;
        }

        $cover = (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate($date)
            ->setSlotIndex($slot)
            ->setAbsentTeacher($absent)
            ->setAssignedGuardia($assigned)
            ->setNotCovered($notCovered)
            ->setGroupName($group);
        $this->em->persist($cover);

        return $cover;
    }

    /**
     * Reads the CSRF token a rendered parte carries for a given mutation form, so a follow-up POST is
     * valid in the same session (mirrors what the browser submits from that form).
     */
    private function tokenFrom(Crawler $crawler, string $action): string
    {
        return (string) $crawler->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');
    }

    /**
     * Reloads a cover from the database after clearing the identity map, so assertions see the persisted
     * state and not a stale in-memory object.
     */
    private function reload(int $id): GuardiaCover
    {
        $this->em->clear();
        $cover = $this->em->getRepository(GuardiaCover::class)->find($id);
        self::assertInstanceOf(GuardiaCover::class, $cover);

        return $cover;
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

    /**
     * The teacher's own "hoy" section lists only the guardias assigned to THEM today — including one
     * flagged as an incident (it is still their guardia today) — and never another teacher's.
     */
    public function testMisGuardiasShowsOnlyMyTodayCovers(): void
    {
        $me = $this->login(coordinator: false);
        $other = $this->user('Otro Guardia', 'otro@centro.test');
        $absent = $this->user('Profe Ausente', 'ausente@centro.test');
        $today = new \DateTimeImmutable('today');

        $this->cover($today, 0, $absent, $me, false, '1ºA');
        $this->cover($today, 1, $absent, $me, false, '2ºB');
        $this->cover($today, 2, $absent, $me, true, '3ºC'); // incidencia, pero es mía y de hoy: se muestra
        // A cover assigned to someone else the same day must not leak into my list.
        $this->cover($today, 3, $absent, $other, false, '4ºD-AJENA');
        $this->em->flush();

        $this->client->request('GET', '/guardias/mias');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.today-guardias', '1ºA');
        self::assertSelectorTextContains('.today-guardias', '3ºC');
        self::assertSelectorTextNotContains('.today-guardias', '4ºD-AJENA');
    }

    /**
     * The "modificar guardia" screen flags the cover as not covered when the box is ticked and clears
     * it when it is not, taking it out of the equitable balance without deleting the parte line. A
     * change is only accepted with a reason, which is recorded in the cover's event log.
     */
    public function testModifyFlagsNotCoveredWithAReason(): void
    {
        $this->login();
        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        $guardia = $this->user('Guardia Uno', 'g1@centro.test');
        $absent = $this->user('Ausente Uno', 'a1@centro.test');
        $date = new \DateTimeImmutable('2025-11-10'); // Monday
        $this->guardiaEntry($year, $guardia, $date);
        $cover = $this->cover($date, 0, $absent, $guardia);
        $this->em->flush();
        $id = (int) $cover->getId();
        $action = '/guardias/'.$id.'/modificar';

        $crawler = $this->client->request('GET', $action);
        self::assertResponseIsSuccessful();
        $this->client->request('POST', $action, ['_token' => $this->tokenFrom($crawler, $action), 'guardia' => (string) $guardia->getId(), 'not_covered' => '1', 'motivo' => 'El sustituto tampoco vino.']);
        self::assertResponseRedirects();
        self::assertTrue($this->reload($id)->isNotCovered());

        // Submitting again without the box clears the flag.
        $crawler = $this->client->request('GET', $action);
        $this->client->request('POST', $action, ['_token' => $this->tokenFrom($crawler, $action), 'guardia' => (string) $guardia->getId(), 'motivo' => 'Al final sí se cubrió.']);
        self::assertFalse($this->reload($id)->isNotCovered());
    }

    /**
     * The coordinator overrides the assigned guardia from the modify screen, and an empty choice clears
     * it. Every change carries a mandatory reason.
     */
    public function testModifyReassignsAndClearsTheGuardia(): void
    {
        $this->login();
        $year = $this->academicYear('2025-2026');
        $this->em->persist($year);
        $guardia = $this->user('Guardia Pool', 'gp@centro.test');
        $absent = $this->user('Ausente Dos', 'a2@centro.test');
        $date = new \DateTimeImmutable('2025-11-10');
        $this->guardiaEntry($year, $guardia, $date);
        $cover = $this->cover($date, 0, $absent, null);
        $this->em->flush();
        $id = (int) $cover->getId();
        $action = '/guardias/'.$id.'/modificar';

        $crawler = $this->client->request('GET', $action);
        $this->client->request('POST', $action, ['_token' => $this->tokenFrom($crawler, $action), 'guardia' => (string) $guardia->getId(), 'motivo' => 'Lo cubre este compañero.']);
        self::assertResponseRedirects();
        self::assertSame($guardia->getId(), $this->reload($id)->getAssignedGuardia()?->getId());

        $crawler = $this->client->request('GET', $action);
        $this->client->request('POST', $action, ['_token' => $this->tokenFrom($crawler, $action), 'guardia' => '', 'motivo' => 'Se retira la asignación.']);
        self::assertNull($this->reload($id)->getAssignedGuardia());
    }

    /**
     * A change without a reason is refused and leaves the cover untouched: the motivo is the record of
     * why a manual change was made.
     */
    public function testModifyRequiresAReason(): void
    {
        $this->login();
        $guardia = $this->user('Guardia Cuatro', 'g4@centro.test');
        $absent = $this->user('Ausente Cuatro', 'a4@centro.test');
        $cover = $this->cover(new \DateTimeImmutable('2025-11-10'), 0, $absent, null);
        $this->em->flush();
        $id = (int) $cover->getId();
        $action = '/guardias/'.$id.'/modificar';

        $crawler = $this->client->request('GET', $action);
        $this->client->request('POST', $action, ['_token' => $this->tokenFrom($crawler, $action), 'guardia' => (string) $guardia->getId(), 'motivo' => '']);

        self::assertResponseRedirects($action);
        self::assertNull($this->reload($id)->getAssignedGuardia());
    }

    /**
     * A mutation with a bad CSRF token is refused and leaves the cover untouched.
     */
    public function testInvalidCsrfTokenIsRejected(): void
    {
        $this->login();
        $absent = $this->user('Ausente Tres', 'a3@centro.test');
        $cover = $this->cover(new \DateTimeImmutable('2025-11-10'), 0, $absent, null);
        $this->em->flush();
        $id = (int) $cover->getId();

        $this->client->request('POST', '/guardias/'.$id.'/modificar', ['_token' => 'wrong', 'motivo' => 'x']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reload($id)->isNotCovered());
    }

    /**
     * The task document is downloadable by the guardia assigned to the cover and by the absent teacher
     * (they need / left the work) and by the coordinator, but a teacher unrelated to it is denied.
     */
    public function testTaskDocumentDownloadIsRestrictedToInvolvedTeachers(): void
    {
        $this->login(); // coordinator, currently authenticated
        $guardia = $this->user('Guardia Doc', 'gdoc@centro.test');
        $absent = $this->user('Ausente Doc', 'adoc@centro.test');
        $stranger = $this->user('Ajeno Doc', 'ajeno@centro.test');
        $cover = $this->cover(new \DateTimeImmutable('2025-11-10'), 0, $absent, $guardia);

        $uploader = self::getContainer()->get(FileUploader::class);
        $path = $uploader->store('%PDF-1.4 contenido de prueba', 'guardia-tasks', 'pdf');
        $cover->setTaskDocumentPath($path)->setTaskDocumentName('tarea.pdf');
        $this->em->flush();
        $url = '/guardias/'.$cover->getId().'/tarea';

        $this->client->request('GET', $url); // coordinator
        self::assertResponseIsSuccessful();

        $this->client->loginUser($guardia);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($absent);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->client->loginUser($stranger);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        $uploader->remove($path);
    }

    /**
     * The private reason for the absence is shown to the coordinator on a cover's detail, but never to
     * the guardia teacher who covers it — even when they open their own cover.
     */
    public function testAbsenceReasonIsHiddenFromTheCoveringTeacher(): void
    {
        $this->login(); // coordinator
        $guardia = $this->user('Guardia Ver', 'gver@centro.test');
        $absent = $this->user('Ausente Ver', 'aver@centro.test');
        $cover = $this->cover(new \DateTimeImmutable('2025-11-10'), 0, $absent, $guardia);
        $cover->getAbsence()->setReason('Cita médica confidencial.');
        $this->em->flush();
        $url = '/guardias/'.$cover->getId().'/ver';

        $this->client->request('GET', $url); // coordinator sees it
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Cita médica confidencial.');

        $this->client->loginUser($guardia); // the covering teacher must not
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Cita médica confidencial.', $crawler->html());
    }
}
