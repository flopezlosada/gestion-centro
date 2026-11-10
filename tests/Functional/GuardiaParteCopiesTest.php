<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\CopyRequest;
use App\Entity\Department;
use App\Entity\GuardiaCover;
use App\Entity\GuardiaTaskBankItem;
use App\Entity\Role;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\EducationLevel;
use App\Enum\PermissionLevel;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Ordering the copies of a guardia's task straight from the parte.
 *
 * The parte assigns a bank task in one click ("Una al azar") and comes back to itself saying "pide las
 * fotocopias", but the only button to do it lived inside the guardia's own screen — so the copies were
 * never ordered. These pin the link on the parte line and the same rules the guardia screen applies:
 * nothing to order without a document, for a guardia that did not happen, or once its hour is over.
 */
final class GuardiaParteCopiesTest extends WebTestCase
{
    use ClockSensitiveTrait;

    /** A Monday of the 2025-2026 course, in the second term. */
    private const string MONDAY = '2026-01-12';

    /** The period the guardia is at: 09:20 to 10:15. */
    private const int SLOT = 2;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AcademicYear $year;

    protected function setUp(): void
    {
        // Before the period starts: the guardia is still ahead, so ordering copies serves someone.
        self::mockTime(self::MONDAY.' 08:00');
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-22'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-22'));
        $this->em->persist($this->year);
    }

    public function testTheParteOffersToOrderTheCopiesOfABankTask(): void
    {
        $this->loginAsCoordination();
        $cover = $this->coverWithBankTask(withDocument: true);

        $links = $this->copyLinks($cover);

        // One on the mobile card and one on the desktop table: the parte paints both.
        self::assertCount(2, $links, 'the line with a bank task offers its copies, in both layouts');
        self::assertSame(['Pedir fotocopias', 'Pedir fotocopias'], $links->each(static fn (Crawler $a): string => $a->text()));
    }

    public function testOrderingFromTheParteComesBackToTheParte(): void
    {
        $this->loginAsCoordination();
        $cover = $this->coverWithBankTask(withDocument: true);
        $uploader = self::getContainer()->get(FileUploader::class);
        // A real file this time: the order takes its own copy of it.
        $cover->setBankItem(null)->setTaskDocumentPath($uploader->store('ficha', 'guardia-tasks', 'pdf'))->setTaskDocumentName('ficha.pdf');
        $this->em->flush();

        $url = $this->copyLinks($cover)->first()->attr('href');
        $crawler = $this->client->request('GET', (string) $url);
        self::assertResponseIsSuccessful();
        self::assertSame($this->parteUrl(), $crawler->filter('.form-actions a')->attr('href'), 'cancelling goes back to the parte too');

        $this->client->request('POST', (string) $url, ['copy_request' => [
            'files' => (string) $crawler->filter('input[name="copy_request[files]"]')->attr('value'),
            'lines' => [['copies' => '25']],
            '_token' => (string) $crawler->filter('input[name="copy_request[_token]"]')->attr('value'),
        ]]);

        self::assertResponseRedirects($this->parteUrl(), null, 'back at the same day and period of the parte, not at the orders list');
    }

    public function testTheParteSaysWhenTheCopiesWereAlreadyOrdered(): void
    {
        $this->loginAsCoordination();
        $cover = $this->coverWithBankTask(withDocument: true);

        $this->em->persist((new CopyRequest())->setCover($cover)->addLine(25)->setContext('E4D')
            ->setRecipient('fotocopias@centro.test')->markSent(new \DateTimeImmutable(self::MONDAY.' 07:30')));
        $this->em->flush();

        $links = $this->copyLinks($cover);

        self::assertCount(2, $links);
        self::assertStringContainsString('Fotocopias pedidas', $links->first()->text(), 'an order already placed is said, so it is not placed twice');
    }

    public function testAnOrderWhoseMailNeverLeftIsNotReadAsOrdered(): void
    {
        $this->loginAsCoordination();
        $cover = $this->coverWithBankTask(withDocument: true);
        // Registered but never sent (the mailer failed): conserjería has nothing.
        $this->em->persist((new CopyRequest())->setCover($cover)->addLine(25)->setContext('E4D'));
        $this->em->flush();

        $crawler = $this->parte();

        self::assertStringNotContainsString('Fotocopias pedidas', $crawler->filter('main')->text());
        self::assertStringContainsString('Fotocopias sin enviar', $crawler->filter('main')->text());
        self::assertStringContainsString('Pedir otra vez', $this->linksFor($crawler, $cover)->first()->text());
    }

    public function testSomeoneWhoOnlyReadsTheParteIsNotOfferedTheCopiesOfAnotherTeachersGuardia(): void
    {
        // Read access is enough to look at the parte, never to act on someone else's class.
        $role = (new Role())->setCode('lectura-'.uniqid())->setName('Lectura de guardias')
            ->setLevel(Area::GUARDIAS, PermissionLevel::READ);
        $this->login((new User())->setFullName('Solo mira')->setEmail('mira@centro.test')->addAssignedRole($role), $role);
        $cover = $this->coverWithBankTask(withDocument: true);

        self::assertCount(0, $this->copyLinks($cover));
    }

    public function testThePeriodInProgressStillOffersTheCopies(): void
    {
        $this->loginAsCoordination();
        $cover = $this->coverWithBankTask(withDocument: true);
        // 09:50, in the middle of the 09:20–10:15 period: the class is happening, the copies still serve.
        self::mockTime(self::MONDAY.' 09:50');

        self::assertCount(2, $this->copyLinks($cover));
    }

    public function testTheParteOffersTheCopiesOfTheDocumentTheAbsentTeacherLeft(): void
    {
        $this->loginAsCoordination();
        $cover = $this->coverWithBankTask(withDocument: false)
            ->setBankItem(null)
            ->setTaskDocumentPath('guardia-tasks/ficha.pdf')
            ->setTaskDocumentName('ficha.pdf');
        $this->em->flush();

        self::assertCount(2, $this->copyLinks($cover), 'the absent teacher\'s own document is printable too');
    }

    public function testNothingIsOfferedWhenTheBankTaskHasNoDocument(): void
    {
        $this->loginAsCoordination();
        $cover = $this->coverWithBankTask(withDocument: false);

        $crawler = $this->parte();

        // The line is there (its bank task is named), it just has nothing to print.
        self::assertStringContainsString('Ficha de repaso', $crawler->text());
        self::assertCount(0, $this->linksFor($crawler, $cover));
    }

    public function testNothingIsOfferedForAGuardiaThatDidNotHappen(): void
    {
        $this->loginAsCoordination();
        $cover = $this->coverWithBankTask(withDocument: true)->setNotCovered(true);
        $this->em->flush();

        self::assertCount(0, $this->copyLinks($cover));
    }

    public function testNothingIsOfferedOnceThePeriodIsOver(): void
    {
        $this->loginAsCoordination();
        $cover = $this->coverWithBankTask(withDocument: true);
        // 10:15 is when the period ends: a minute later the copies serve nobody.
        self::mockTime(self::MONDAY.' 10:16');

        self::assertCount(0, $this->copyLinks($cover));
    }

    public function testNothingIsOfferedOnAPastDay(): void
    {
        $this->loginAsCoordination();
        $cover = $this->coverWithBankTask(withDocument: true);
        self::mockTime('2026-01-13 08:00');

        self::assertCount(0, $this->copyLinks($cover));
    }

    /**
     * Logs in as the guardia coordination: write access to the area, the people who work the parte.
     */
    private function loginAsCoordination(): void
    {
        $role = (new Role())->setCode('guardias-'.uniqid())->setName('Coordinación de guardias')
            ->setLevel(Area::GUARDIAS, PermissionLevel::WRITE);
        $this->login((new User())->setFullName('Coordinación')->setEmail('coordinacion@centro.test')->addAssignedRole($role), $role);
    }

    /**
     * Persists and logs in a user with the given role.
     */
    private function login(User $user, Role $role): void
    {
        $this->em->persist($role);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }

    /**
     * A parte line on the test's Monday with a task taken from the bank, and the timetable entry that
     * gives the parte its period (without one the screen shows its "no timetable" empty state).
     *
     * The guardia teacher is a real person and the bank task carries no sections, suggested copies or
     * description — the nulls a bank task created in a hurry has.
     *
     * @param bool $withDocument whether the bank task has a file to print
     */
    private function coverWithBankTask(bool $withDocument): GuardiaCover
    {
        $department = (new Department())->setCode('maths')->setName('Matemáticas');
        $absent = (new User())->setFullName('Ausente')->setEmail('ausente@centro.test');
        $guardia = (new User())->setFullName('De guardia')->setEmail('guardia@centro.test');
        $this->em->persist($department);
        $this->em->persist($absent);
        $this->em->persist($guardia);

        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)->setTeacher($absent)->setWeekday(Weekday::MONDAY)->setSlotIndex(self::SLOT)
            ->setStartsAt(new \DateTimeImmutable('09:20'))->setEndsAt(new \DateTimeImmutable('10:15'))
            ->setKind(ScheduleActivityKind::LECTIVE)->setGroupName('E4D')->setRoomName('A12')->setSubjectName('Matemáticas'));

        $item = (new GuardiaTaskBankItem())
            ->setAcademicYear($this->year)
            ->setDepartment($department)
            ->setLevel(EducationLevel::ESO_4)
            ->setSubject('Matemáticas')
            ->setTitle('Ficha de repaso');
        if ($withDocument) {
            // The parte only asks whether there is something to print; the file itself is the order's job.
            $item->addDocument('guardia-task-bank/ficha.pdf', 'ficha.pdf');
        }
        $this->em->persist($item);

        $date = new \DateTimeImmutable(self::MONDAY);
        $absence = (new Absence())->setAbsentTeacher($absent)->setDate($date);
        $this->em->persist($absence);

        $cover = (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate($date)
            ->setSlotIndex(self::SLOT)
            ->setAbsentTeacher($absent)
            ->setAssignedGuardia($guardia)
            ->setGroupName('E4D')
            ->setRoomName('A12')
            ->setSubjectName('Matemáticas')
            ->setBankItem($item);
        $this->em->persist($cover);
        $this->em->flush();

        return $cover;
    }

    /**
     * Opens the parte at the guardia's day and period.
     */
    private function parte(): Crawler
    {
        $crawler = $this->client->request('GET', $this->parteUrl());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * The parte at the guardia's day and period, as the app links it.
     */
    private function parteUrl(): string
    {
        return '/guardias?date='.self::MONDAY.'&slot='.self::SLOT;
    }

    /**
     * The parte's links to order this guardia's copies (the mobile cards and the desktop table each
     * paint one).
     */
    private function copyLinks(GuardiaCover $cover): Crawler
    {
        return $this->linksFor($this->parte(), $cover);
    }

    /**
     * The links to order this guardia's copies in an already loaded page.
     */
    private function linksFor(Crawler $crawler, GuardiaCover $cover): Crawler
    {
        return $crawler->filter(sprintf('a[href="/fotocopias/guardia/%d?volver=parte"]', (int) $cover->getId()));
    }
}
