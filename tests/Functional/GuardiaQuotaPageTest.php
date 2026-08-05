<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AcademicYear;
use App\Entity\GuardiaQuota;
use App\Entity\Role;
use App\Entity\ScheduleEntry;
use App\Entity\Substitution;
use App\Entity\TimeSlot;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\ScheduleActivityKind;
use App\Enum\TimeSlotKind;
use App\Enum\Weekday;
use App\Guardia\SubstitutionApplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The quota screen: how many guardias each teacher takes on over a course.
 *
 * Gated WRITE on the {@see Area::GUARDIAS} matrix — deciding who is exempt is not a read-only look at
 * the rota — and scoped to the teachers who actually have a timetable in the course.
 */
final class GuardiaQuotaPageTest extends WebTestCase
{
    private const URL = '/guardias/cupos';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAPlainTeacherCannotSeeTheQuotas(): void
    {
        $this->login(false);
        $this->client->request('GET', self::URL.'?curso=2025-2026');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testReadAccessAloneIsNotEnoughToSetQuotas(): void
    {
        // The rest of the module opens its viewing screens on READ; this one does not, because typing a
        // zero here takes somebody out of the rota for the whole year.
        $this->loginWith(PermissionLevel::READ);
        $this->client->request('GET', self::URL.'?curso=2025-2026');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTheTableListsTheTeachersWithATimetableAndTheirTeachingLoad(): void
    {
        $this->login();
        $year = $this->courseWithFrame('2025-2026', 6);
        $ana = $this->user('Ana Docente', 'ana@centro.test');
        $luis = $this->user('Luis Docente', 'luis@centro.test');
        $this->lectiveEntry($year, $ana, Weekday::MONDAY, 0);
        $this->lectiveEntry($year, $ana, Weekday::MONDAY, 1);
        // Somebody with no timetable in this course is not staff here and must not get a row.
        $this->user('Fuera Delcentro', 'fuera@centro.test');
        $this->lectiveEntry($year, $luis, Weekday::TUESDAY, 0);
        $this->em->flush();

        $crawler = $this->client->request('GET', self::URL.'?curso=2025-2026');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Ana Docente');
        self::assertStringNotContainsString('Fuera Delcentro', (string) $this->client->getResponse()->getContent());
        // Six periods × five days × five people.
        self::assertStringContainsString('150', $crawler->filter('.worklist-stats')->text());
        // Nothing typed yet, so both boxes start at zero...
        self::assertSame('0', $crawler->filter('#lective-'.$ana->getId())->attr('value'));
        self::assertSame('0', $crawler->filter('#break-'.$ana->getId())->attr('value'));
        // ...and the teaching load is shown beside them: Ana has two periods, Luis one.
        self::assertStringContainsString('2', $crawler->filter('#lective-'.$ana->getId())->closest('tr')?->text() ?? '');
        self::assertStringContainsString('1', $crawler->filter('#lective-'.$luis->getId())->closest('tr')?->text() ?? '');
    }

    public function testSavingStoresTheQuotasOfTheCourse(): void
    {
        $this->login();
        $year = $this->courseWithFrame('2025-2026', 6);
        $ana = $this->user('Ana Docente', 'ana@centro.test');
        $this->lectiveEntry($year, $ana, Weekday::MONDAY, 0);
        $this->em->flush();

        $crawler = $this->client->request('GET', self::URL.'?curso=2025-2026');
        $this->client->request('POST', self::URL, [
            '_token' => $this->tokenFrom($crawler),
            'curso' => '2025-2026',
            'lective' => [(string) $ana->getId() => '3'],
            'break' => [(string) $ana->getId() => '1'],
        ]);

        self::assertResponseRedirects();
        $quota = $this->quotaOf($ana);
        self::assertInstanceOf(GuardiaQuota::class, $quota);
        self::assertSame(3, $quota->getLectiveDuties());
        self::assertSame(1, $quota->getBreakDuties());
    }

    public function testWhoeverCoversALongLeaveShowsTheQuotaOfThePostAndHasNoBoxes(): void
    {
        // Quien cubre una baja larga tiene el horario, así que aparece en la tabla, pero el cupo es del
        // PUESTO: se lee el de la persona a la que sustituye. Y va SIN casillas a propósito — con ellas,
        // el navegador mandaría esa cifra en cada envío de la tabla, se hubiera tocado o no, y le crearía
        // una fila propia que a partir de ahí dejaría de seguir al cupo real. Guardar por cualquier otro
        // motivo la congelaría sin que nadie lo pidiera.
        $this->login();
        $year = $this->courseWithFrame('2025-2026', 6);
        $elena = $this->user('Elena Titular', 'elena@centro.test');
        $sara = $this->user('Sara Sustituta', 'sara@centro.test');
        $this->lectiveEntry($year, $elena, Weekday::MONDAY, 0);
        $this->em->persist((new GuardiaQuota())->setAcademicYear($year)->setTeacher($elena)->setLectiveDuties(3)->setBreakDuties(1));
        $this->em->flush();

        $substitution = (new Substitution())
            ->setAcademicYear($year)
            ->setSubstitutedTeacher($elena)
            ->setSubstitute($sara)
            ->setStartedOn(new \DateTimeImmutable('2025-11-10'));
        self::getContainer()->get(SubstitutionApplier::class)->open($substitution, new \DateTimeImmutable('2025-11-10'));

        $crawler = $this->client->request('GET', self::URL.'?curso=2025-2026');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Hereda el cupo de Elena Titular');
        self::assertCount(0, $crawler->filter('#lective-'.$sara->getId()), 'sin casilla que enviar');

        // Y guardar la tabla —donde el navegador solo manda lo que tiene casilla— la deja intacta.
        $this->client->request('POST', self::URL, [
            '_token' => $this->tokenFrom($crawler),
            'curso' => '2025-2026',
        ]);

        self::assertNull($this->quotaOf($sara), 'no se le congela un cupo propio por pulsar Guardar');
    }

    public function testATeacherLeftAtZeroIsStoredSoTheExemptionIsOnTheRecord(): void
    {
        // A zero has to be written down. Without a row there is no way to tell somebody the equipo
        // directivo deliberately excused from somebody nobody has got to yet, and a fresh course would
        // read as a claustro where everyone is exempt.
        $this->login();
        $year = $this->courseWithFrame('2025-2026', 6);
        $ana = $this->user('Ana Docente', 'ana@centro.test');
        $this->lectiveEntry($year, $ana, Weekday::MONDAY, 0);
        $this->em->flush();

        $crawler = $this->client->request('GET', self::URL.'?curso=2025-2026');
        self::assertSelectorTextContains('body', 'Sin decidir');

        $this->client->request('POST', self::URL, [
            '_token' => $this->tokenFrom($crawler),
            'curso' => '2025-2026',
            'lective' => [(string) $ana->getId() => '0'],
            'break' => [(string) $ana->getId() => '0'],
        ]);

        self::assertResponseRedirects();
        $quota = $this->quotaOf($ana);
        self::assertInstanceOf(GuardiaQuota::class, $quota);
        self::assertTrue($quota->isExempt());

        $this->client->request('GET', self::URL.'?curso=2025-2026');
        self::assertSelectorTextContains('body', 'Exenta/o');
    }

    public function testAQuotaBeyondTheOfferedRangeIsClamped(): void
    {
        $this->login();
        $year = $this->courseWithFrame('2025-2026', 6);
        $ana = $this->user('Ana Docente', 'ana@centro.test');
        $this->lectiveEntry($year, $ana, Weekday::MONDAY, 0);
        $this->em->flush();

        $crawler = $this->client->request('GET', self::URL.'?curso=2025-2026');
        $this->client->request('POST', self::URL, [
            '_token' => $this->tokenFrom($crawler),
            'curso' => '2025-2026',
            'lective' => [(string) $ana->getId() => '999'],
            'break' => [(string) $ana->getId() => '-4'],
        ]);

        $quota = $this->quotaOf($ana);
        self::assertInstanceOf(GuardiaQuota::class, $quota);
        self::assertSame(GuardiaQuota::MAX, $quota->getLectiveDuties());
        self::assertSame(0, $quota->getBreakDuties());
    }

    public function testAQuotaForSomebodyOutsideTheCourseIsIgnored(): void
    {
        // The form is the list of who may have a quota. A hand-built request naming anybody else is
        // dropped rather than trusted: the teachers are read from the course, never from the payload.
        $this->login();
        $year = $this->courseWithFrame('2025-2026', 6);
        $ana = $this->user('Ana Docente', 'ana@centro.test');
        $this->lectiveEntry($year, $ana, Weekday::MONDAY, 0);
        $outsider = $this->user('Fuera Delcentro', 'fuera@centro.test');
        $this->em->flush();

        $crawler = $this->client->request('GET', self::URL.'?curso=2025-2026');
        $this->client->request('POST', self::URL, [
            '_token' => $this->tokenFrom($crawler),
            'curso' => '2025-2026',
            'lective' => [(string) $outsider->getId() => '4'],
            'break' => [],
        ]);

        self::assertResponseRedirects();
        self::assertNull($this->quotaOf($outsider));
    }

    public function testAPartialPostLeavesTheTeachersItDoesNotMentionAlone(): void
    {
        // The browser always submits every box, so this only happens on a hand-built or truncated
        // request — and there, reading "absent" as "zero" would wipe the quota of everybody not named.
        $this->login();
        $year = $this->courseWithFrame('2025-2026', 6);
        $ana = $this->user('Ana Docente', 'ana@centro.test');
        $luis = $this->user('Luis Docente', 'luis@centro.test');
        $this->lectiveEntry($year, $ana, Weekday::MONDAY, 0);
        $this->lectiveEntry($year, $luis, Weekday::TUESDAY, 0);
        $this->em->flush();

        $crawler = $this->client->request('GET', self::URL.'?curso=2025-2026');
        $token = $this->tokenFrom($crawler);
        $this->client->request('POST', self::URL, [
            '_token' => $token,
            'curso' => '2025-2026',
            'lective' => [(string) $ana->getId() => '3', (string) $luis->getId() => '2'],
            'break' => [],
        ]);

        // Now a second submit that only mentions Ana must not disturb Luis.
        $crawler = $this->client->request('GET', self::URL.'?curso=2025-2026');
        $this->client->request('POST', self::URL, [
            '_token' => $this->tokenFrom($crawler),
            'curso' => '2025-2026',
            'lective' => [(string) $ana->getId() => '1'],
            'break' => [],
        ]);

        self::assertSame(1, $this->quotaOf($ana)?->getLectiveDuties());
        self::assertSame(2, $this->quotaOf($luis)?->getLectiveDuties());
    }

    public function testAnInvalidCsrfTokenIsRejected(): void
    {
        $this->login();
        $year = $this->courseWithFrame('2025-2026', 6);
        $ana = $this->user('Ana Docente', 'ana@centro.test');
        $this->lectiveEntry($year, $ana, Weekday::MONDAY, 0);
        $this->em->flush();

        $this->client->request('POST', self::URL, [
            '_token' => 'no-es-el-token',
            'curso' => '2025-2026',
            'lective' => [(string) $ana->getId() => '3'],
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->quotaOf($ana));
    }

    public function testACourseWithNoTimetableSaysSoInsteadOfShowingAnEmptyTable(): void
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
        return $coordinator ? $this->loginWith(PermissionLevel::WRITE) : $this->loginWith(null);
    }

    /** Logs in a user holding the given permission level on the Guardias area (null = no role at all). */
    private function loginWith(?PermissionLevel $level): User
    {
        $user = (new User())->setFullName('Coordina Test')->setEmail('coordina@centro.test');
        if (null !== $level) {
            $role = (new Role())->setCode('guardias')->setName('Coordinación de guardias')->setLevel(Area::GUARDIAS, $level);
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

    /** A course whose marco horario has the given number of teaching periods, so the balance is real. */
    private function courseWithFrame(string $schoolYear, int $lectiveSlots): AcademicYear
    {
        $year = $this->academicYear($schoolYear);
        $this->em->persist($year);

        for ($i = 0; $i < $lectiveSlots; ++$i) {
            $slot = (new TimeSlot())
                ->setAcademicYear($year)
                ->setSlotIndex($i)
                ->setStartsAt(new \DateTimeImmutable('08:25'))
                ->setEndsAt(new \DateTimeImmutable('09:20'))
                ->setKind(TimeSlotKind::LECTIVE);
            $this->em->persist($slot);
        }

        return $year;
    }

    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    private function lectiveEntry(AcademicYear $year, User $teacher, Weekday $weekday, int $slot): ScheduleEntry
    {
        $entry = (new ScheduleEntry())
            ->setAcademicYear($year)
            ->setTeacher($teacher)
            ->setWeekday($weekday)
            ->setSlotIndex($slot)
            ->setStartsAt(new \DateTimeImmutable('08:25'))
            ->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(ScheduleActivityKind::LECTIVE)
            ->setGroupName('1ºA');
        $this->em->persist($entry);

        return $entry;
    }

    /**
     * Reads the CSRF token out of the rendered form. Never built from the container: the token store is
     * session-backed and asking for it outside a request throws.
     */
    private function tokenFrom(Crawler $crawler): string
    {
        return (string) $crawler->filter('form[action="'.self::URL.'"] input[name="_token"]')->attr('value');
    }

    private function quotaOf(User $teacher): ?GuardiaQuota
    {
        $this->em->clear();

        return $this->em->getRepository(GuardiaQuota::class)->findOneBy(['teacher' => $teacher->getId()]);
    }
}
