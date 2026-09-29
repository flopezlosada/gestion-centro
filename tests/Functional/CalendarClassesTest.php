<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\User;
use App\Enum\Weekday;
use App\Tests\Support\BuildsTheCentresTimetable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Las clases del propio docente en el Calendario: en la vista de día y en la de semana, no en la de mes
 * (serían treinta por celda). Fecha FIJA: un lunes del curso 2025-2026.
 */
final class CalendarClassesTest extends WebTestCase
{
    use BuildsTheCentresTimetable;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $teacher;
    private AcademicYear $year;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->year = $this->courseWithTheCentresFrame($this->em);
        $this->teacher = (new User())->setFullName('Carolina Calendario')->setEmail('carolina.cal@educa.madrid.org');
        $this->em->persist($this->teacher);
        $this->classCell($this->em, $this->year, $this->teacher, Weekday::MONDAY, 7, 'B1A', 'S ACTOS');
        $this->classCell($this->em, $this->year, $this->teacher, Weekday::MONDAY, 7, 'B1B', 'S ACTOS');
        $this->em->flush();
    }

    public function testTheDayViewListsTheTeachersClassesWithHourGroupsAndRoom(): void
    {
        $this->client->loginUser($this->teacher);
        $this->client->request('GET', '/calendario?vista=dia&fecha=2026-01-12');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cal-block--class .cal-block__time', '13:35–14:30');
        self::assertSelectorTextContains('.cal-block--class .cal-block__title', 'B1A, B1B');
        self::assertSelectorTextContains('.cal-block--class .cal-block__subtitle', 'Literatura Universal');
        self::assertSelectorTextContains('.cal-block--class .cal-block__subtitle', 'S ACTOS');
    }

    public function testTheWeekViewShowsOneBlockPerClass(): void
    {
        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/calendario?vista=semana&fecha=2026-01-12');

        self::assertCount(1, $crawler->filter('.cal-block--class'), 'una clase aunque sean dos celdas');
        self::assertSelectorTextContains('.cal-block--class', 'B1A, B1B');
    }

    public function testTheMonthViewDoesNotListClasses(): void
    {
        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/calendario?vista=mes&fecha=2026-01-12');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.calendar-class'));
    }

    /**
     * Las horas de guardia del horario salen en la rejilla aunque no falte nadie: sin ellas, un día de
     * guardia sin ausencias parecía un día sin guardia. La del recreo, con su nombre.
     */
    public function testTheDayViewShowsTheStandingGuardiaHoursRecreoIncluded(): void
    {
        $this->guardiaCell($this->em, $this->year, $this->teacher, Weekday::MONDAY, 2, '10:15', '11:10');
        $this->guardiaCell($this->em, $this->year, $this->teacher, Weekday::MONDAY, 3, '11:10', '11:35');
        $this->em->flush();

        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/calendario?vista=dia&fecha=2026-01-12');

        self::assertResponseIsSuccessful();
        $duties = $crawler->filter('.cal-block--duty');
        self::assertCount(2, $duties);
        self::assertStringContainsString('10:15–11:10', $duties->eq(0)->filter('.cal-block__time')->text());
        self::assertSame('Guardia', $duties->eq(0)->filter('.cal-block__title')->text());
        self::assertSame('Guardia de recreo', $duties->eq(1)->filter('.cal-block__title')->text());
        self::assertSame('/guardias/mias', $duties->eq(0)->attr('href'));
    }

    /**
     * Con alguien ya a quien cubrir en esa hora, manda la cobertura (dice a quién y dónde): la hora de
     * guardia no se pinta además, que sería el mismo rato dos veces.
     */
    public function testAnAssignedCoverReplacesTheStandingGuardiaHour(): void
    {
        $this->guardiaCell($this->em, $this->year, $this->teacher, Weekday::MONDAY, 2, '10:15', '11:10');
        $absent = (new User())->setFullName('Ausente Calendario')->setEmail('ausente.cal@educa.madrid.org');
        $this->em->persist($absent);
        $date = new \DateTimeImmutable('2026-01-12');
        $absence = (new Absence())->setAbsentTeacher($absent)->setDate($date)->addSlotIndexes([2]);
        $this->em->persist($absence);
        $this->em->persist((new GuardiaCover())->setAbsence($absence)->setDate($date)->setSlotIndex(2)
            ->setAbsentTeacher($absent)->setAssignedGuardia($this->teacher)->setGroupName('1ºA'));
        $this->em->flush();

        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/calendario?vista=dia&fecha=2026-01-12');

        self::assertCount(0, $crawler->filter('.cal-block--duty'));
        self::assertCount(1, $crawler->filter('.cal-block--guardia'));
    }

    /** Otra persona no ve las clases de nadie más: el calendario es de quien lo mira. */
    public function testAnotherPersonDoesNotSeeThem(): void
    {
        $other = (new User())->setFullName('Otra Persona')->setEmail('otra.cal@educa.madrid.org');
        $this->em->persist($other);
        $this->em->flush();

        $this->client->loginUser($other);
        $crawler = $this->client->request('GET', '/calendario?vista=dia&fecha=2026-01-12');

        self::assertCount(0, $crawler->filter('.cal-block--class'));
    }
}
