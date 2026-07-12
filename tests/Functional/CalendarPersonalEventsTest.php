<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PersonalEvent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The calendar lays each user's own private personal events out on the day they start — in the day,
 * week and month views — and never shows them on anyone else's calendar. The anchor comes from the
 * "fecha" query param, so fixed dates are stable regardless of the wall-clock.
 */
final class CalendarPersonalEventsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    private function eventFor(User $owner, string $title): PersonalEvent
    {
        // 15 July 2026, a Wednesday — a plain teaching day, well inside the month grid.
        $event = new PersonalEvent($owner, $title, new \DateTimeImmutable('2026-07-15 10:00'));
        $this->em->persist($event);

        return $event;
    }

    public function testMonthViewShowsOwnPersonalEvent(): void
    {
        $owner = $this->user('profe@centro.test');
        $this->eventFor($owner, 'Tutoría con familia');
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/calendario?vista=mes&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.calendar-grid', 'Tutoría con familia');
    }

    public function testDayViewShowsOwnPersonalEvent(): void
    {
        $owner = $this->user('profe@centro.test');
        $this->eventFor($owner, 'Tutoría con familia');
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/calendario?vista=dia&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.calendar-dayview', 'Tutoría con familia');
    }

    public function testWeekViewIncludesLateEventOnTheLastDayOfTheRange(): void
    {
        $owner = $this->user('profe@centro.test');
        // Sunday 19 July 2026 closes the week of the 15th; a 23:30 start must not be dropped by the
        // range end, which the controller widens from midnight to 23:59:59.
        $event = new PersonalEvent($owner, 'Reunión tardía', new \DateTimeImmutable('2026-07-19 23:30'));
        $this->em->persist($event);
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/calendario?vista=semana&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.calendar-grid', 'Reunión tardía');
    }

    public function testAnotherUsersPersonalEventIsNotOnMyCalendar(): void
    {
        $owner = $this->user('duena@centro.test');
        $this->eventFor($owner, 'Cita privada ajena');
        $stranger = $this->user('otro@centro.test');
        $this->em->flush();

        $this->client->loginUser($stranger);
        $this->client->request('GET', '/calendario?vista=mes&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Cita privada ajena', (string) $this->client->getResponse()->getContent());
    }

    public function testAnotherUsersPersonalEventIsNotOnMyDayView(): void
    {
        $owner = $this->user('duena@centro.test');
        $this->eventFor($owner, 'Cita privada ajena');
        $stranger = $this->user('otro@centro.test');
        $this->em->flush();

        $this->client->loginUser($stranger);
        $this->client->request('GET', '/calendario?vista=dia&fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Cita privada ajena', (string) $this->client->getResponse()->getContent());
    }
}
