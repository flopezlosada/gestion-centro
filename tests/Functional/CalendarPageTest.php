<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\TaskType;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The monthly calendar must render for any authenticated user and lay each task they may see out on
 * the grid of the month its deadline falls into.
 */
final class CalendarPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testCalendarShowsTaskOnItsMonth(): void
    {
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente');
        $this->em->persist($teacherRole);

        $teacher = (new User())->setFullName('Profe Test')->setEmail('profe@centro.test')->addAssignedRole($teacherRole);
        $this->em->persist($teacher);

        $maths = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($maths);

        $due = new \DateTimeImmutable('2026-07-15');
        $task = new Task('Memoria del departamento', SchoolYear::current($due), $due, TaskType::WITH_DELIVERABLE);
        $task->setUnit($maths)->setAssignedUser($teacher);
        $this->em->persist($task);
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/calendario?mes=2026-07');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.calendar-grid');
        self::assertSelectorTextContains('.calendar-grid', 'Memoria del departamento');
    }

    public function testCalendarHidesTasksOutsideTheUsersScope(): void
    {
        $teacher = (new User())->setFullName('Profe Test')->setEmail('profe@centro.test');
        $colleague = (new User())->setFullName('Colega Test')->setEmail('colega@centro.test');
        array_map($this->em->persist(...), [$teacher, $colleague]);

        $maths = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($maths);
        $teacher->setUnit($maths);
        $colleague->setUnit($maths);

        $due = new \DateTimeImmutable('2026-07-15');
        $year = SchoolYear::current($due);
        $mine = (new Task('Tarea propia', $year, $due, TaskType::SIMPLE))->setUnit($maths)->setAssignedUser($teacher);
        $others = (new Task('Tarea ajena', $year, $due, TaskType::SIMPLE))->setUnit($maths)->setAssignedUser($colleague);
        array_map($this->em->persist(...), [$mine, $others]);
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/calendario?mes=2026-07');
        $content = (string) $this->client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Tarea propia', $content);
        self::assertStringNotContainsString('Tarea ajena', $content, "a colleague's task is not on the teacher's calendar");
    }
}
