<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PersonalEvent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Creating a recurring personal event materialises one row per occurrence sharing a series id, and
 * the edit page lets the owner delete either just one occurrence or the whole series.
 */
final class PersonalEventRecurrenceTest extends WebTestCase
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

    /**
     * @return list<PersonalEvent> the persisted occurrences (weekly from 2 March 2026)
     */
    private function makeSeries(User $owner, string $title, int $count): array
    {
        $seriesId = bin2hex(random_bytes(8));
        $events = [];
        for ($i = 0; $i < $count; ++$i) {
            $start = (new \DateTimeImmutable('2026-03-02 10:00'))->modify('+'.(7 * $i).' days');
            $event = (new PersonalEvent($owner, $title, $start))->setSeriesId($seriesId);
            $this->em->persist($event);
            $events[] = $event;
        }
        $this->em->flush();

        return $events;
    }

    public function testCreatingWeeklyRecurringEventMaterialisesOneRowPerOccurrence(): void
    {
        $user = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/agenda/nueva');
        $form = $crawler->selectButton('Crear evento')->form();
        $form['personal_event_form[title]'] = 'Reunión semanal';
        $form['personal_event_form[day]'] = '2026-03-02';
        $form['personal_event_form[startTime]'] = '10:00';
        $form['personal_event_form[repeat]'] = 'weekly';
        $form['personal_event_form[repeatUntil]'] = '2026-03-30';
        $this->client->submit($form);

        self::assertResponseRedirects('/agenda');
        $this->em->clear();
        $events = $this->em->getRepository(PersonalEvent::class)->findBy(['title' => 'Reunión semanal'], ['startAt' => 'ASC']);
        self::assertCount(5, $events);
        self::assertSame('2026-03-02 10:00', $events[0]->getStartAt()->format('Y-m-d H:i'));
        self::assertSame('2026-03-30 10:00', $events[4]->getStartAt()->format('Y-m-d H:i'));
        // Every occurrence shares one non-null series id.
        $series = $events[0]->getSeriesId();
        self::assertNotNull($series);
        foreach ($events as $event) {
            self::assertSame($series, $event->getSeriesId());
        }
    }

    public function testRecurringWithoutAnEndDayIsRejected(): void
    {
        $user = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/agenda/nueva');
        $form = $crawler->selectButton('Crear evento')->form();
        $form['personal_event_form[title]'] = 'Sin fin de repetición';
        $form['personal_event_form[day]'] = '2026-03-02';
        $form['personal_event_form[startTime]'] = '10:00';
        $form['personal_event_form[repeat]'] = 'weekly';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('hasta qué día se repite', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->em->getRepository(PersonalEvent::class)->count(['title' => 'Sin fin de repetición']));
    }

    public function testDeletingTheSeriesRemovesEveryOccurrence(): void
    {
        $user = $this->user('profe@centro.test');
        $events = $this->makeSeries($user, 'Reunión semanal', 3);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/agenda/'.$events[0]->getId().'/editar');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Borrar toda la serie')->form());

        self::assertResponseRedirects('/agenda');
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(PersonalEvent::class)->count(['title' => 'Reunión semanal']));
    }

    public function testDeletingOneOccurrenceKeepsTheRestOfTheSeries(): void
    {
        $user = $this->user('profe@centro.test');
        $events = $this->makeSeries($user, 'Reunión semanal', 3);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/agenda/'.$events[0]->getId().'/editar');
        $this->client->submit($crawler->selectButton('Borrar solo este')->form());

        self::assertResponseRedirects('/agenda');
        $this->em->clear();
        self::assertSame(2, $this->em->getRepository(PersonalEvent::class)->count(['title' => 'Reunión semanal']));
    }

    public function testAnotherUserCannotDeleteSomeoneElsesSeries(): void
    {
        $owner = $this->user('duena@centro.test');
        $stranger = $this->user('otro@centro.test');
        $events = $this->makeSeries($owner, 'Serie ajena', 3);

        $this->client->loginUser($stranger);
        // A stranger's series delete is refused (CSRF or ownership gate) and the series must survive.
        $this->client->request('POST', '/agenda/'.$events[0]->getId().'/borrar-serie', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertSame(3, $this->em->getRepository(PersonalEvent::class)->count(['title' => 'Serie ajena']));
    }

    public function testRecurringBeyondTwoYearsIsRejected(): void
    {
        $user = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/agenda/nueva');
        $form = $crawler->selectButton('Crear evento')->form();
        $form['personal_event_form[title]'] = 'Serie demasiado larga';
        $form['personal_event_form[day]'] = '2026-03-02';
        $form['personal_event_form[startTime]'] = '10:00';
        $form['personal_event_form[repeat]'] = 'weekly';
        $form['personal_event_form[repeatUntil]'] = '2029-03-02'; // three years out
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('más de dos años', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->em->getRepository(PersonalEvent::class)->count(['title' => 'Serie demasiado larga']));
    }

    public function testRepeatUntilBeforeTheStartDayIsRejected(): void
    {
        $user = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/agenda/nueva');
        $form = $crawler->selectButton('Crear evento')->form();
        $form['personal_event_form[title]'] = 'Fin antes del inicio';
        $form['personal_event_form[day]'] = '2026-03-10';
        $form['personal_event_form[startTime]'] = '10:00';
        $form['personal_event_form[repeat]'] = 'weekly';
        $form['personal_event_form[repeatUntil]'] = '2026-03-02'; // before the start day
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('anterior al día', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->em->getRepository(PersonalEvent::class)->count(['title' => 'Fin antes del inicio']));
    }

    public function testNoTimeRecurringMaterialisesNoTimeOccurrences(): void
    {
        $user = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/agenda/nueva');
        $form = $crawler->selectButton('Crear evento')->form();
        $form['personal_event_form[title]'] = 'Recordatorio semanal';
        $form['personal_event_form[day]'] = '2026-03-02';
        $form['personal_event_form[repeat]'] = 'weekly';
        $form['personal_event_form[repeatUntil]'] = '2026-03-16';
        // No start time: every occurrence is a no-time reminder.
        $this->client->submit($form);

        self::assertResponseRedirects('/agenda');
        $this->em->clear();
        $events = $this->em->getRepository(PersonalEvent::class)->findBy(['title' => 'Recordatorio semanal'], ['startAt' => 'ASC']);
        self::assertCount(3, $events);
        foreach ($events as $event) {
            self::assertTrue($event->isAllDay());
            self::assertNull($event->getEndAt());
        }
    }
}
