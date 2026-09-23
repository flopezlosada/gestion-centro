<?php

declare(strict_types=1);

namespace App\Tests\Functional;

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

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $year = $this->courseWithTheCentresFrame($this->em);
        $this->teacher = (new User())->setFullName('Carolina Calendario')->setEmail('carolina.cal@educa.madrid.org');
        $this->em->persist($this->teacher);
        $this->classCell($this->em, $year, $this->teacher, Weekday::MONDAY, 7, 'B1A', 'S ACTOS');
        $this->classCell($this->em, $year, $this->teacher, Weekday::MONDAY, 7, 'B1B', 'S ACTOS');
        $this->em->flush();
    }

    public function testTheDayViewListsTheTeachersClassesWithHourGroupsAndRoom(): void
    {
        $this->client->loginUser($this->teacher);
        $this->client->request('GET', '/calendario?vista=dia&fecha=2026-01-12');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.agenda-item--class', '13:35 · B1A, B1B · Literatura Universal');
        self::assertSelectorTextContains('.agenda-item--class', 'Clase · S ACTOS');
    }

    public function testTheWeekViewShowsOneLinePerClass(): void
    {
        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/calendario?vista=semana&fecha=2026-01-12');

        self::assertCount(1, $crawler->filter('.calendar-class'), 'una clase aunque sean dos celdas');
        self::assertSelectorTextContains('.calendar-class', 'B1A, B1B · S ACTOS');
    }

    public function testTheMonthViewDoesNotListClasses(): void
    {
        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', '/calendario?vista=mes&fecha=2026-01-12');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.calendar-class'));
    }

    /** Otra persona no ve las clases de nadie más: el calendario es de quien lo mira. */
    public function testAnotherPersonDoesNotSeeThem(): void
    {
        $other = (new User())->setFullName('Otra Persona')->setEmail('otra.cal@educa.madrid.org');
        $this->em->persist($other);
        $this->em->flush();

        $this->client->loginUser($other);
        $crawler = $this->client->request('GET', '/calendario?vista=dia&fecha=2026-01-12');

        self::assertCount(0, $crawler->filter('.agenda-item--class'));
    }
}
