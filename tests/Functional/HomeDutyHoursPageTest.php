<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\ScheduleEntry;
use App\Entity\TimeSlot;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\TimeSlotKind;
use App\Enum\Weekday;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Las horas de guardia del horario en «Tu día» de Inicio. Un día de guardia sin ausencias no enseñaba la
 * guardia en ningún sitio de Inicio, y la tira de arriba decía «Hoy no tienes guardia»: justo lo contrario.
 */
final class HomeDutyHoursPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** Sin nadie a quien cubrir, la hora de guardia ocupa su sitio en «Tu día» y lleva a «Mis guardias». */
    public function testAGuardiaHourWithNobodyToCoverIsListedInTheDay(): void
    {
        $today = $this->schoolDayOrSkip();
        $teacher = $this->scenario($today);

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $row = $this->dutyRows($crawler);
        self::assertCount(1, $row);
        self::assertStringContainsString('12:30', $row->text());
        self::assertSame('/guardias/mias', $row->attr('href'));
        self::assertStringContainsString('Hoy no tienes a nadie a quien cubrir', $crawler->filter('.no-guardia')->text());
        self::assertStringNotContainsString('Hoy no tienes guardia', $crawler->filter('.no-guardia')->text());
    }

    /**
     * Una guardia de recreo del horario no es «nadie a quien cubrir»: en el recreo no hay clases, se vigila.
     * Y si es lo único del día, la tira lo dice así en vez de «no tienes a nadie a quien cubrir».
     */
    public function testARecreoHourIsVigilanceNotSomebodyToCover(): void
    {
        $today = $this->schoolDayOrSkip();
        $teacher = $this->scenario($today, recreo: true);

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->dutyRows($crawler));
        $row = $crawler->filter('.day-row')->reduce(static fn (Crawler $n): bool => str_contains($n->text(), 'vigilancia del recreo'));
        self::assertCount(1, $row);
        self::assertStringContainsString('Guardia de recreo', $row->text());
        self::assertStringContainsString('Hoy solo tienes el recreo', $crawler->filter('.no-guardia')->text());
    }

    /** Con cobertura en esa hora se pinta la cobertura, que dice a quién y dónde, y no la hora vacía. */
    public function testAGuardiaHourWithACoverShowsTheCoverInstead(): void
    {
        $today = $this->schoolDayOrSkip();
        $teacher = $this->scenario($today);
        $absent = (new User())->setFullName('Ausente Hoy')->setEmail('ausente.hoy@centro.test');
        $this->em->persist($absent);
        $absence = (new Absence())->setAbsentTeacher($absent)->setDate($today);
        $absence->addSlotIndexes([4]);
        $this->em->persist($absence);
        $this->em->persist((new GuardiaCover())->setAbsence($absence)->setDate($today)->setSlotIndex(4)
            ->setAbsentTeacher($absent)->setAssignedGuardia($teacher)->setGroupName('2ºB'));
        $this->em->flush();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->dutyRows($crawler));
        self::assertCount(1, $crawler->filter('.day-row')->reduce(static fn (Crawler $n): bool => str_contains($n->text(), '2ºB')));
    }

    /**
     * Inicio se pinta para HOY: el escenario tiene que caer en un día lectivo. En fin de semana no hay
     * horas de guardia que enseñar.
     *
     * @return \DateTimeImmutable today
     */
    private function schoolDayOrSkip(): \DateTimeImmutable
    {
        $today = new \DateTimeImmutable('today');
        if ((int) $today->format('N') >= 6) {
            self::markTestSkipped('En fin de semana no hay horas de guardia que pintar.');
        }

        return $today;
    }

    /**
     * The rows of "Tu día" that are a guardia hour with nobody to cover.
     *
     * @param Crawler $crawler the rendered home
     *
     * @return Crawler the matching rows
     */
    private function dutyRows(Crawler $crawler): Crawler
    {
        return $crawler->filter('.day-row')->reduce(static fn (Crawler $n): bool => str_contains($n->text(), 'nadie a quien cubrir'));
    }

    /**
     * A teacher with one guardia hour today (5ª, 12:30; a recreo if asked) in a course whose terms cover every day of the year,
     * so the test does not depend on falling in term time — only on not being a weekend.
     *
     * @param \DateTimeImmutable $today  the day the home is rendered for
     * @param bool               $recreo whether that hour is a recreo of the marco horario
     *
     * @return User the teacher
     */
    private function scenario(\DateTimeImmutable $today, bool $recreo = false): User
    {
        $schoolYear = SchoolYear::current($today);
        $start = (int) substr($schoolYear, 0, 4);
        $year = (new AcademicYear())
            ->setSchoolYear($schoolYear)
            ->setTerm1Start(new \DateTimeImmutable($start.'-09-01'))
            ->setTerm1End(new \DateTimeImmutable($start.'-12-31'))
            ->setTerm2Start(new \DateTimeImmutable(($start + 1).'-01-01'))
            ->setTerm2End(new \DateTimeImmutable(($start + 1).'-03-31'))
            ->setTerm3Start(new \DateTimeImmutable(($start + 1).'-04-01'))
            ->setTerm3End(new \DateTimeImmutable(($start + 1).'-08-31'));
        $this->em->persist($year);

        if ($recreo) {
            $this->em->persist((new TimeSlot())->setAcademicYear($year)->setSlotIndex(4)->setKind(TimeSlotKind::BREAK_TIME)
                ->setStartsAt(new \DateTimeImmutable('12:30'))->setEndsAt(new \DateTimeImmutable('13:25')));
        }

        $teacher = (new User())->setFullName('Esther Guardia')->setEmail('esther.guardia@centro.test');
        $this->em->persist($teacher);

        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($year)
            ->setTeacher($teacher)
            ->setWeekday(Weekday::from((int) $today->format('N')))
            ->setSlotIndex(4)
            ->setStartsAt(new \DateTimeImmutable('12:30'))
            ->setEndsAt(new \DateTimeImmutable('13:25'))
            ->setKind(ScheduleActivityKind::GUARDIA));
        $this->em->flush();

        return $teacher;
    }
}
