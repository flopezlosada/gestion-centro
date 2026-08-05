<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AcademicYear;
use App\Entity\GuardiaQuota;
use App\Entity\Role;
use App\Entity\ScheduleEntry;
use App\Entity\TimeSlot;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\ScheduleActivityKind;
use App\Enum\ScheduleEntrySource;
use App\Enum\TimeSlotKind;
use App\Enum\Weekday;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The weekly rota screen: proposing writes nothing, approving writes only the engine's own cells, and
 * anything a person or the export put there survives both.
 */
final class GuardiaRotaPageTest extends WebTestCase
{
    private const URL = '/guardias/cuadrante';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAPlainTeacherCannotOpenTheRota(): void
    {
        $this->login(false);
        $this->client->request('GET', self::URL.'?curso=2025-2026');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testProposingWritesNothing(): void
    {
        // The whole point of the draft: it can be looked at and thrown away. Nothing is parked in a
        // table, a session or a temp file, because the engine is deterministic and can just be re-run.
        $this->login();
        $this->course('2025-2026', [new RotaTeacherFixture('Ana Docente', 'ana@centro.test', 3)]);

        $this->client->request('GET', self::URL.'?curso=2025-2026&propuesta=1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Esto es una propuesta');
        self::assertSame(0, $this->countDutyCells());
    }

    public function testApprovingWritesTheRotaIntoTheTimetable(): void
    {
        $this->login();
        $this->course('2025-2026', [
            new RotaTeacherFixture('Ana Docente', 'ana@centro.test', 3),
            new RotaTeacherFixture('Luis Docente', 'luis@centro.test', 3),
        ]);

        $crawler = $this->client->request('GET', self::URL.'?curso=2025-2026&propuesta=1');
        $this->client->request('POST', self::URL.'/aprobar', [
            '_token' => $this->tokenFrom($crawler),
            'curso' => '2025-2026',
        ]);

        self::assertResponseRedirects();
        // Two teachers on a quota of three: six cells, all of them the engine's.
        self::assertSame(6, $this->countDutyCells());
        self::assertSame(6, $this->countDutyCells(ScheduleEntrySource::ENGINE));
    }

    public function testApprovingTwiceDoesNotDuplicateTheRota(): void
    {
        $this->login();
        $this->course('2025-2026', [new RotaTeacherFixture('Ana Docente', 'ana@centro.test', 3)]);

        for ($i = 0; $i < 2; ++$i) {
            $crawler = $this->client->request('GET', self::URL.'?curso=2025-2026&propuesta=1');
            $this->client->request('POST', self::URL.'/aprobar', [
                '_token' => $this->tokenFrom($crawler),
                'curso' => '2025-2026',
            ]);
        }

        self::assertSame(3, $this->countDutyCells());
    }

    public function testAHandMarkedGuardiaSurvivesANewProposal(): void
    {
        // The reason MANUAL and ENGINE are told apart at all: re-proposing must never undo a retouch.
        $this->login();
        [$year, $teachers] = $this->course('2025-2026', [
            new RotaTeacherFixture('Ana Docente', 'ana@centro.test', 1),
            new RotaTeacherFixture('Luis Docente', 'luis@centro.test', 1),
        ]);
        $this->dutyCell($year, $teachers['luis@centro.test'], Weekday::FRIDAY, 0, ScheduleEntrySource::MANUAL);
        $this->em->flush();

        $crawler = $this->client->request('GET', self::URL.'?curso=2025-2026&propuesta=1');
        $this->client->request('POST', self::URL.'/aprobar', [
            '_token' => $this->tokenFrom($crawler),
            'curso' => '2025-2026',
        ]);

        self::assertSame(1, $this->countDutyCells(ScheduleEntrySource::MANUAL), 'the hand-marked guardia was lost');
        // And it is not written a second time under the engine's name.
        self::assertSame(0, $this->duplicatePlacements());
    }

    public function testAGuardiaFromThePenalaraExportIsRespectedAndNotDuplicated(): void
    {
        // Where a centre's export already carries guardias, those are the official rota. Leaving them
        // out of the proposal put the same teacher on duty twice in one period, once under each source.
        $this->login();
        [$year, $teachers] = $this->course('2025-2026', [
            new RotaTeacherFixture('Ana Docente', 'ana@centro.test', 2),
        ]);
        $this->dutyCell($year, $teachers['ana@centro.test'], Weekday::MONDAY, 0, ScheduleEntrySource::PENALARA);
        $this->em->flush();

        $crawler = $this->client->request('GET', self::URL.'?curso=2025-2026&propuesta=1');
        $this->client->request('POST', self::URL.'/aprobar', [
            '_token' => $this->tokenFrom($crawler),
            'curso' => '2025-2026',
        ]);

        self::assertSame(1, $this->countDutyCells(ScheduleEntrySource::PENALARA));
        self::assertSame(0, $this->duplicatePlacements(), 'somebody ended up on duty twice in one period');
        // Quota of two, one already spent by the export: the engine may add exactly one more.
        self::assertSame(1, $this->countDutyCells(ScheduleEntrySource::ENGINE));
    }

    public function testAnInvalidCsrfTokenIsRejected(): void
    {
        $this->login();
        $this->course('2025-2026', [new RotaTeacherFixture('Ana Docente', 'ana@centro.test', 3)]);

        $this->client->request('POST', self::URL.'/aprobar', ['_token' => 'no', 'curso' => '2025-2026']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countDutyCells());
    }

    public function testAProposalWithNoQuotaBlamesTheQuotasAndNotTheTimetable(): void
    {
        // What the centre actually saw on 05-08-2026: the timetable was loaded, guardia_quota had zero
        // rows, and the screen reported all 150 places of the week as "eso no se arregla con cupos, es el
        // horario" — sending dirección to look at the one thing that was fine.
        $this->login();
        $this->course('2025-2026', [new RotaTeacherFixture('Ana Docente', 'ana@centro.test', 0)]);

        $this->client->request('GET', self::URL.'?curso=2025-2026&propuesta=1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'no hay ningún cupo');
        // The exact sentence that misled the centre. Asserted whole rather than by a fragment like "es el
        // horario", which the sidebar's own links could satisfy by accident.
        self::assertSelectorTextNotContains('body', 'eso no se arregla con cupos', 'the screen blamed the timetable for an empty quota table');
        // And the grid must not claim the course has no guardias: it is a draft, not the timetable.
        self::assertSelectorTextNotContains('body', 'Todavía no hay ninguna guardia en el horario');
        // No publish button either: publishing nothing can only delete (see the test below).
        self::assertSelectorNotExists('form[action="'.self::URL.'/aprobar"]');
    }

    public function testPublishingAProposalThatPlacesNobodyDoesNotWipeTheRota(): void
    {
        // Publishing is a REPLACE: delete every engine cell of the course, then insert. Hand it a proposal
        // that places nobody and it deletes the published rota and puts nothing back. Reachable without
        // any bad intent: propose and publish, somebody clears the quotas, and the tab still open gets a
        // second press of Publicar.
        $this->login();
        $this->course('2025-2026', [new RotaTeacherFixture('Ana Docente', 'ana@centro.test', 3)]);

        $crawler = $this->client->request('GET', self::URL.'?curso=2025-2026&propuesta=1');
        $token = $this->tokenFrom($crawler);
        $this->client->request('POST', self::URL.'/aprobar', ['_token' => $token, 'curso' => '2025-2026']);
        self::assertSame(3, $this->countDutyCells(ScheduleEntrySource::ENGINE), 'nothing was published to begin with');

        // The quotas go back to zero, and the same form is posted again.
        $this->em->createQueryBuilder()
            ->update(GuardiaQuota::class, 'q')
            ->set('q.lectiveDuties', 0)
            ->getQuery()
            ->execute();
        $this->em->clear();

        $this->client->request('POST', self::URL.'/aprobar', ['_token' => $token, 'curso' => '2025-2026']);

        self::assertSame(3, $this->countDutyCells(ScheduleEntrySource::ENGINE), 'the published rota was wiped by an empty proposal');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'No se ha publicado nada');
    }

    public function testACourseWithNoTimetableSaysSoInsteadOfOfferingAProposal(): void
    {
        $this->login();
        $this->em->persist($this->academicYear('2025-2026'));
        $this->em->flush();

        $this->client->request('GET', self::URL.'?curso=2025-2026');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No hay horario importado');
    }

    // ---------------------------------------------------------------- helpers

    private function login(bool $coordinator = true): User
    {
        $user = (new User())->setFullName('Coordina Test')->setEmail('coordina@centro.test');
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

    /**
     * A course with a one-period day, the given teachers on the timetable and their quota already set.
     *
     * One period keeps the arithmetic readable: the week is then 5 places of guardia and 10 of standby,
     * and a quota of three is three cells, not a number nobody can check by hand.
     *
     * @param string                    $schoolYear the course
     * @param list<RotaTeacherFixture>  $teachers   who to put on the timetable
     *
     * @return array{0: AcademicYear, 1: array<string, User>} the course and its teachers by email
     */
    private function course(string $schoolYear, array $teachers): array
    {
        $year = $this->academicYear($schoolYear);
        $this->em->persist($year);

        $slot = (new TimeSlot())
            ->setAcademicYear($year)
            ->setSlotIndex(0)
            ->setStartsAt(new \DateTimeImmutable('08:25'))
            ->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(TimeSlotKind::LECTIVE);
        $this->em->persist($slot);

        $byEmail = [];
        foreach ($teachers as $fixture) {
            $user = (new User())->setFullName($fixture->name)->setEmail($fixture->email);
            $this->em->persist($user);
            $byEmail[$fixture->email] = $user;

            // A lesson somewhere in the week, so the teacher counts as staff of this course. Saturday is
            // outside the school week, so it never collides with a period the engine wants to fill.
            $lesson = (new ScheduleEntry())
                ->setAcademicYear($year)
                ->setTeacher($user)
                ->setWeekday(Weekday::SATURDAY)
                ->setSlotIndex(0)
                ->setStartsAt(new \DateTimeImmutable('08:25'))
                ->setEndsAt(new \DateTimeImmutable('09:20'))
                ->setKind(ScheduleActivityKind::LECTIVE)
                ->setGroupName('1ºA');
            $this->em->persist($lesson);

            $this->em->persist((new GuardiaQuota())->setAcademicYear($year)->setTeacher($user)->setLectiveDuties($fixture->quota));
        }
        $this->em->flush();

        return [$year, $byEmail];
    }

    private function dutyCell(AcademicYear $year, User $teacher, Weekday $weekday, int $slot, ScheduleEntrySource $source): ScheduleEntry
    {
        $cell = (new ScheduleEntry())
            ->setAcademicYear($year)
            ->setTeacher($teacher)
            ->setWeekday($weekday)
            ->setSlotIndex($slot)
            ->setStartsAt(new \DateTimeImmutable('08:25'))
            ->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(ScheduleActivityKind::GUARDIA)
            ->setSource($source);
        $this->em->persist($cell);

        return $cell;
    }

    /**
     * Reads the CSRF token out of the rendered approve form — never built from the container, whose
     * token store is session-backed and throws outside a request.
     */
    private function tokenFrom(Crawler $crawler): string
    {
        return (string) $crawler->filter('form[action="'.self::URL.'/aprobar"] input[name="_token"]')->attr('value');
    }

    private function countDutyCells(?ScheduleEntrySource $source = null): int
    {
        $this->em->clear();
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(ScheduleEntry::class, 's')
            ->where('s.kind IN (:kinds)')
            ->setParameter('kinds', [ScheduleActivityKind::GUARDIA, ScheduleActivityKind::COLLABORATOR]);
        if (null !== $source) {
            $qb->andWhere('s.source = :source')->setParameter('source', $source);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** How many (teacher, weekday, period) triples hold more than one duty cell. */
    private function duplicatePlacements(): int
    {
        $this->em->clear();
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(s.teacher) AS t', 's.weekday AS w', 's.slotIndex AS sl', 'COUNT(s.id) AS n')
            ->from(ScheduleEntry::class, 's')
            ->where('s.kind IN (:kinds)')
            ->setParameter('kinds', [ScheduleActivityKind::GUARDIA, ScheduleActivityKind::COLLABORATOR])
            ->groupBy('s.teacher')->addGroupBy('s.weekday')->addGroupBy('s.slotIndex')
            ->getQuery()
            ->getResult();

        $duplicates = 0;
        foreach ($rows as $row) {
            if ((int) $row['n'] > 1) {
                ++$duplicates;
            }
        }

        return $duplicates;
    }
}

/** One teacher to seed a course with, so the test bodies read as a sentence. */
final class RotaTeacherFixture
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly int $quota,
    ) {
    }
}
