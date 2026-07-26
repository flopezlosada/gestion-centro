<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Department;
use App\Entity\PersonalEvent;
use App\Entity\Role;
use App\Entity\TaskResponsibility;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskType;
use App\Support\TaskStatus;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The landing agenda (served at /agenda) mixes the user's tasks and their private personal events —
 * but only their own: a personal event is never shown on someone else's agenda. The site root (/) is
 * the personal home ("qué me toca hoy"), a separate page.
 */
final class HomeAgendaTest extends WebTestCase
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

    public function testOwnPersonalEventShowsOnTheHomeAgenda(): void
    {
        $user = $this->user('profe@centro.test');
        // A couple of days out so it lands in the "next 7 days" bucket regardless of wall-clock.
        $event = new PersonalEvent($user, 'Tutoría con familia', new \DateTimeImmutable('+2 days'));
        $this->em->persist($event);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/agenda');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Tutoría con familia', (string) $this->client->getResponse()->getContent());
    }

    public function testOverduePersonalEventStillShowsOnTheHomeAgenda(): void
    {
        $user = $this->user('profe@centro.test');
        // A few days in the past: within the "from a month back" window, so it must still surface
        // (in the "Vencidas" bucket) rather than be silently dropped.
        $event = new PersonalEvent($user, 'Recordatorio vencido', new \DateTimeImmutable('-5 days'));
        $this->em->persist($event);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/agenda');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Recordatorio vencido', (string) $this->client->getResponse()->getContent());
    }

    public function testDonePersonalEventShowsOnTheHomeAgenda(): void
    {
        $user = $this->user('profe@centro.test');
        $event = (new PersonalEvent($user, 'Evento ya hecho', new \DateTimeImmutable('+2 days')))->setDone(true);
        $this->em->persist($event);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/agenda');

        self::assertResponseIsSuccessful();
        // Done entries are kept apart in the "Hechas" bucket, but still rendered.
        self::assertStringContainsString('Evento ya hecho', (string) $this->client->getResponse()->getContent());
    }

    public function testAnotherUsersPersonalEventDoesNotShowOnMyHomeAgenda(): void
    {
        $owner = $this->user('duena@centro.test');
        $me = $this->user('yo@centro.test');
        $event = new PersonalEvent($owner, 'Cita privada ajena', new \DateTimeImmutable('+2 days'));
        $this->em->persist($event);
        $this->em->flush();

        $this->client->loginUser($me);
        $this->client->request('GET', '/agenda');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Cita privada ajena', (string) $this->client->getResponse()->getContent());
    }

    public function testTheSiteRootShowsThePersonalHome(): void
    {
        $user = $this->user('profe@centro.test');
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        // El Inicio ya no es un "panel" genérico: encabeza con el saludo personal al usuario. Se asserta
        // el nombre, no la fórmula de cortesía, para que el test aguante si el saludo pasa a variar
        // según la hora del día.
        self::assertSelectorTextContains('h1.home-greeting', 'Profe');
    }

    public function testTheHomeRendersTasksAndEventsWithRealContentWithoutErroring(): void
    {
        $user = $this->user('profe@centro.test');
        $today = new \DateTimeImmutable('today');
        // Una tarea que vence hoy → "Por hacer"; una cita con hora hoy → "Con hora"; un recordatorio sin
        // hora → "Por hacer". Con strict_variables activo en el entorno de test, poblar de verdad la
        // plantilla nueva caza cualquier error de variable (el 500 de g.taskNote habría saltado aquí).
        $task = (new Task('Entregar memoria PGA', SchoolYear::current($today), $today, TaskType::SIMPLE))->setAssignedUser($user);
        $this->em->persist($task);
        $this->em->persist((new PersonalEvent($user, 'Reunión de departamento', $today->setTime(10, 30)))->setAllDay(false));
        $this->em->persist((new PersonalEvent($user, 'Llamar a la editorial', $today))->setAllDay(true));
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Entregar memoria PGA', $html, 'la tarea de hoy sale en "Por hacer"');
        self::assertStringContainsString('Reunión de departamento', $html, 'la cita con hora sale en "Con hora"');
        self::assertStringContainsString('Llamar a la editorial', $html, 'el recordatorio sin hora sale en "Por hacer"');
    }

    /**
     * The "por validar" figure of the "Mi departamento" module is what THIS user may actually validate,
     * and the workflow decides that: nobody validates their own work, even running the department. Counting
     * every submitted task of the department put her own in — a jefa de departamento outranks the Tutor/a
     * role of her own task, so it slipped through — and the tile promised work the task page then refused.
     */
    public function testTheDepartmentModuleOnlyCountsWhatThisUserMayReallyValidate(): void
    {
        $head = (new Role())->setCode('head')->setName('Jefatura de departamento')->setHierarchyLevel(10)->setPerDepartment(true);
        $tutor = (new Role())->setCode('tutor')->setName('Tutor/a')->setPerDepartment(true);
        array_map($this->em->persist(...), [$head, $tutor]);
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($maths);

        $boss = (new User())->setFullName('Mercedes Jefa')->setEmail('jefa@centro.test')->setUnit($maths)->addAssignedRole($head)->addAssignedRole($tutor);
        $member = (new User())->setFullName('Pedro Tutor')->setEmail('tutor@centro.test')->setUnit($maths)->addAssignedRole($tutor);
        array_map($this->em->persist(...), [$boss, $member]);

        $year = SchoolYear::current(new \DateTimeImmutable('today'));
        foreach ([[$boss, 'Mi propia acta'], [$member, 'El acta de Pedro']] as [$who, $title]) {
            $task = (new Task($title, $year, new \DateTimeImmutable('+3 days'), TaskType::SIMPLE))
                ->setUnit($maths)
                ->setAssignedUser($who)
                ->setResponsibility(new TaskResponsibility($tutor, $maths))
                ->setStatus(TaskStatus::SUBMITTED);
            $this->em->persist($task);
        }
        $this->em->flush();

        $this->client->loginUser($boss);
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        // Dos entregadas en el departamento, pero solo UNA es suya de validar.
        self::assertSame('1', trim($crawler->filter('.module-tile__num')->first()->text()), 'only the subordinate\'s task counts as pending her validation');
        $listed = $crawler->filter('.module-row__title')->each(static fn ($node): string => $node->text());
        self::assertContains('El acta de Pedro', $listed);
        self::assertNotContains('Mi propia acta', $listed, 'you are never offered your own task to validate');
    }
}
