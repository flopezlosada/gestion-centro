<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Department;
use App\Entity\PersonalEvent;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskType;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A one-click "hecho" tick must return to the surface it was pressed on. The tick lives both on Inicio
 * and on the calendar's day view, and ticking something in the calendar used to dump you on Inicio,
 * throwing away the day you were looking at.
 *
 * The form says where it came from with a plain day, so the destination is a route this app generates
 * itself ({@see \App\Support\TickOutcome}) — never a URL from the request, which is what makes an open
 * redirect unrepresentable rather than merely blocked. These tests pin both halves: the honest round
 * trip, and what a tampered field can (not) do.
 */
final class TickOutcomeTest extends WebTestCase
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
     * A task due on a fixed day, assigned to its own owner so they may tick it.
     *
     * @return array{0: User, 1: int, 2: string} the owner, the task id and its deadline as "YYYY-MM-DD"
     */
    private function seedTask(): array
    {
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $owner = $this->user('profe@centro.test');
        $owner->setUnit($dept);
        // Anchored in the app's zone, like the calendar, so the seeded day and the rendered day agree.
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));
        $task = new Task('Memoria', SchoolYear::current($today), $today, TaskType::SIMPLE);
        $task->setAssignedUser($owner)->setCreatedBy($owner);
        $this->em->persist($task);
        $this->em->flush();

        return [$owner, (int) $task->getId(), $today->format('Y-m-d')];
    }

    public function testTickingATaskInTheCalendarComesBackToThatDay(): void
    {
        [$owner, $taskId, $day] = $this->seedTask();
        $this->client->loginUser($owner);

        // Submit the real form rendered by the day view, so the day it carries is the page's, not ours.
        $crawler = $this->client->request('GET', '/calendario?vista=dia&fecha='.$day);
        $this->client->submit($crawler->filter('form.agenda-check')->first()->form());

        self::assertResponseRedirects('/calendario?vista=dia&fecha='.$day);
        $this->em->clear();
        self::assertTrue($this->em->getRepository(Task::class)->find($taskId)?->isCheckboxDone());
    }

    public function testTickingATaskOnTheHomeComesBackToTheHome(): void
    {
        [$owner] = $this->seedTask();
        $this->client->loginUser($owner);

        // Inicio's form carries no day: the task is due today, so it sits in "Por hacer".
        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->filter('form.tasklist__check-form')->first()->form());

        self::assertResponseRedirects('/');
    }

    public function testTickingAReminderInTheCalendarComesBackToThatDay(): void
    {
        $owner = $this->user('profe@centro.test');
        // Midday, so no offset between the runner's zone and the app's can move it to another day.
        $start = (new \DateTimeImmutable('+3 days'))->setTime(12, 0);
        $event = (new PersonalEvent($owner, 'Llamar a la familia', $start))->setAllDay(true);
        $this->em->persist($event);
        $this->em->flush();
        $eventId = (int) $event->getId();
        $day = $start->format('Y-m-d');

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/calendario?vista=dia&fecha='.$day);
        $this->client->submit($crawler->filter('form.agenda-check')->first()->form());

        self::assertResponseRedirects('/calendario?vista=dia&fecha='.$day);
        $this->em->clear();
        self::assertTrue($this->em->getRepository(PersonalEvent::class)->find($eventId)?->isDone());
    }

    public function testATamperedReturnFieldCannotSendYouOffSite(): void
    {
        // The worst a forged "dia" can do is pick another day of your own calendar; anything that is not
        // a date at all falls back to Inicio. An absolute URL must never come back as the destination.
        [$owner, $taskId, $day] = $this->seedTask();
        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/calendario?vista=dia&fecha='.$day);
        $token = (string) $crawler->filter('form.agenda-check input[name="_token"]')->attr('value');

        $this->client->request('POST', '/tareas/'.$taskId.'/hecho', [
            '_token' => $token,
            'dia' => 'https://evil.example/phishing',
        ]);

        self::assertResponseRedirects('/');
    }

    public function testAnImpossibleDateInTheReturnFieldFallsBackToTheHome(): void
    {
        // 30 February parses shape-wise but is not a real day: CalendarDate rejects it instead of
        // rolling it over into March, so the fallback applies.
        [$owner, $taskId, $day] = $this->seedTask();
        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/calendario?vista=dia&fecha='.$day);
        $token = (string) $crawler->filter('form.agenda-check input[name="_token"]')->attr('value');

        $this->client->request('POST', '/tareas/'.$taskId.'/hecho', [
            '_token' => $token,
            'dia' => '2026-02-30',
        ]);

        self::assertResponseRedirects('/');
    }
}
