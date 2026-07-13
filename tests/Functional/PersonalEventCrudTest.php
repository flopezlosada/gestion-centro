<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\EventCategory;
use App\Entity\PersonalEvent;
use App\Entity\User;
use App\Enum\CategoryColor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The teacher's personal agenda: entries are created owned by their author, timed entries compose the
 * day and times into instants, and — the whole point — an entry is private, so another user can
 * neither see nor edit it.
 */
final class PersonalEventCrudTest extends WebTestCase
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

    public function testNewEventFormRenders(): void
    {
        $user = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/agenda/nueva');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testNewEventPrefillsDayFromQuery(): void
    {
        // Arriving from the calendar's "+ Nuevo evento" carries the clicked day as ?fecha=; the form's
        // day field must render already filled with it.
        $user = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/agenda/nueva?fecha=2026-07-15');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[name="personal_event_form[day]"][value="2026-07-15"]');
    }

    public function testNewEventIgnoresAnInvalidFechaQuery(): void
    {
        $user = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($user);

        // A non-date value must not blow up: the form simply renders with an empty day.
        $this->client->request('GET', '/agenda/nueva?fecha=no-es-fecha');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[name="personal_event_form[day]"]');
    }

    public function testCreateTimedEventRecordsOwnerAndComposesInstants(): void
    {
        $owner = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/agenda/nueva');
        $form = $crawler->selectButton('Crear evento')->form();
        $form['personal_event_form[title]'] = 'Tutoría con familia';
        $form['personal_event_form[day]'] = '2026-09-15';
        $form['personal_event_form[startTime]'] = '10:00';
        $form['personal_event_form[endTime]'] = '11:00';
        $this->client->submit($form);

        self::assertResponseRedirects('/agenda');
        $this->em->clear();
        $event = $this->em->getRepository(PersonalEvent::class)->findOneBy(['title' => 'Tutoría con familia']);
        self::assertNotNull($event);
        self::assertSame($owner->getId(), $event->getOwner()->getId());
        self::assertFalse($event->isAllDay());
        self::assertSame('2026-09-15 10:00', $event->getStartAt()->format('Y-m-d H:i'));
        self::assertSame('2026-09-15 11:00', $event->getEndAt()?->format('Y-m-d H:i'));
    }

    public function testCreateNoTimeReminderHasNoTime(): void
    {
        $owner = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/agenda/nueva');
        $form = $crawler->selectButton('Crear evento')->form();
        $form['personal_event_form[title]'] = 'Llamar a la familia de Pepito';
        $form['personal_event_form[day]'] = '2026-09-20';
        // No start time chosen: it is a reminder, sits on the day with no time.
        $this->client->submit($form);

        self::assertResponseRedirects('/agenda');
        $this->em->clear();
        $event = $this->em->getRepository(PersonalEvent::class)->findOneBy(['title' => 'Llamar a la familia de Pepito']);
        self::assertNotNull($event);
        self::assertTrue($event->isAllDay());
        self::assertSame('2026-09-20 00:00', $event->getStartAt()->format('Y-m-d H:i'));
        self::assertNull($event->getEndAt());
    }

    public function testEndTimeWithoutStartTimeIsRejected(): void
    {
        $owner = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/agenda/nueva');
        $form = $crawler->selectButton('Crear evento')->form();
        $form['personal_event_form[title]'] = 'Fin sin inicio';
        $form['personal_event_form[day]'] = '2026-09-15';
        // An end time with no start time makes no sense.
        $form['personal_event_form[endTime]'] = '11:00';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('hora de inicio', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->em->getRepository(PersonalEvent::class)->findOneBy(['title' => 'Fin sin inicio']));
    }

    public function testEndTimeBeforeStartTimeIsRejected(): void
    {
        $owner = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/agenda/nueva');
        $form = $crawler->selectButton('Crear evento')->form();
        $form['personal_event_form[title]'] = 'Fin antes que inicio';
        $form['personal_event_form[day]'] = '2026-09-15';
        $form['personal_event_form[startTime]'] = '11:00';
        $form['personal_event_form[endTime]'] = '10:00';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('posterior a la de inicio', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->em->getRepository(PersonalEvent::class)->findOneBy(['title' => 'Fin antes que inicio']));
    }

    public function testAnotherUserCannotSeeOrEditSomeoneElsesEvent(): void
    {
        $owner = $this->user('duena@centro.test');
        $stranger = $this->user('otro@centro.test');
        // Relative to now: the agenda lists from today onward, so a fixed past date would make the
        // "not in the list" assertion pass for the wrong reason (past, not privacy) after that day.
        $event = new PersonalEvent($owner, 'Cita privada', new \DateTimeImmutable('+10 days'));
        $this->em->persist($event);
        $this->em->flush();

        // The stranger does not see it in their own agenda...
        $this->client->loginUser($stranger);
        $this->client->request('GET', '/agenda');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Cita privada', (string) $this->client->getResponse()->getContent());

        // ...and cannot open its edit page.
        $this->client->request('GET', '/agenda/'.$event->getId().'/editar');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnotherUserCannotDeleteSomeoneElsesEvent(): void
    {
        $owner = $this->user('duena@centro.test');
        $stranger = $this->user('otro@centro.test');
        $event = new PersonalEvent($owner, 'Cita privada', new \DateTimeImmutable('+10 days'));
        $this->em->persist($event);
        $this->em->flush();
        $id = (int) $event->getId();

        $this->client->loginUser($stranger);
        // A stranger's delete is refused (by the CSRF gate or the ownership gate — both are correct
        // protections) and the event must survive. The ownership gate itself is isolated by the GET
        // edit test above; here we assert the destructive route neither succeeds nor mutates.
        $this->client->request('POST', '/agenda/'.$id.'/borrar', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(PersonalEvent::class)->find($id));
    }

    public function testOwnerSeesTheirOwnEventInTheAgenda(): void
    {
        $owner = $this->user('profe@centro.test');
        // Relative to now: the agenda lists entries from today onward, so a fixed past date would make
        // this flaky once wall-clock time passes it.
        $event = new PersonalEvent($owner, 'Reunión de departamento', new \DateTimeImmutable('+30 days'));
        $this->em->persist($event);
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/agenda');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Reunión de departamento', (string) $this->client->getResponse()->getContent());
    }

    public function testCreateEventWithChosenCategoryPersistsIt(): void
    {
        $owner = $this->user('profe@centro.test');
        $category = (new EventCategory())->setName('Claustro')->setColor(CategoryColor::TEAL);
        $this->em->persist($category);
        $this->em->flush();
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/agenda/nueva');
        $form = $crawler->selectButton('Crear evento')->form();
        $form['personal_event_form[title]'] = 'Reunión de claustro';
        $form['personal_event_form[day]'] = '2026-09-15';
        $form['personal_event_form[startTime]'] = '10:00';
        $form['personal_event_form[category]'] = (string) $category->getId();
        $this->client->submit($form);

        self::assertResponseRedirects('/agenda');
        $this->em->clear();
        $event = $this->em->getRepository(PersonalEvent::class)->findOneBy(['title' => 'Reunión de claustro']);
        self::assertNotNull($event);
        self::assertSame($category->getId(), $event->getCategory()?->getId());
    }

    public function testEventWithoutAChosenCategoryIsUncategorised(): void
    {
        $owner = $this->user('profe@centro.test');
        $this->em->flush();
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/agenda/nueva');
        $form = $crawler->selectButton('Crear evento')->form();
        $form['personal_event_form[title]'] = 'Nota rápida';
        $form['personal_event_form[day]'] = '2026-09-15';
        $form['personal_event_form[startTime]'] = '10:00';
        $this->client->submit($form);

        self::assertResponseRedirects('/agenda');
        $this->em->clear();
        $event = $this->em->getRepository(PersonalEvent::class)->findOneBy(['title' => 'Nota rápida']);
        self::assertNotNull($event);
        self::assertNull($event->getCategory());
    }

    public function testEditingPreloadsAndChangesTheCategory(): void
    {
        $owner = $this->user('profe@centro.test');
        // Names outside the seeded catalogue (General/Docencia/Reunión/Tutoría/Personal, present in
        // the test DB), so they cannot clash with a seeded row under the unique-name constraint.
        $meeting = (new EventCategory())->setName('Claustro')->setColor(CategoryColor::TEAL);
        $tutoring = (new EventCategory())->setName('Excursión')->setColor(CategoryColor::GREEN);
        $this->em->persist($meeting);
        $this->em->persist($tutoring);
        $event = (new PersonalEvent($owner, 'Reunión de nivel', new \DateTimeImmutable('2026-09-15 10:00')))
            ->setCategory($meeting);
        $this->em->persist($event);
        $this->em->flush();
        $id = (int) $event->getId();
        $tutoringId = (int) $tutoring->getId();

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/agenda/'.$id.'/editar');
        self::assertResponseIsSuccessful();
        // The edit form preloads the current category as the selected option.
        self::assertSelectorExists('[name="personal_event_form[category]"] option[value="'.$meeting->getId().'"][selected]');

        $form = $crawler->selectButton('Guardar')->form();
        $form['personal_event_form[category]'] = (string) $tutoringId;
        $this->client->submit($form);

        self::assertResponseRedirects('/agenda');
        $this->em->clear();
        $reloaded = $this->em->getRepository(PersonalEvent::class)->find($id);
        self::assertNotNull($reloaded);
        self::assertSame($tutoringId, $reloaded->getCategory()?->getId());
    }
}
