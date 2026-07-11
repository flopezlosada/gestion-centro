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
 * The course plan at /tareas is open to any authenticated user but scoped by the organisation chart:
 * a teacher only sees their own tasks, while a manager sees everything under the units they lead —
 * here via hierarchy alone, without the superuser flag.
 */
final class TaskScopeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * A director on top of maths, a teacher and a colleague in maths, and one task assigned to each of
     * the latter two in the current course.
     *
     * @return array{director: User, teacher: User, mineTitle: string, colleagueTitle: string, colleagueTask: Task}
     */
    private function seed(): array
    {
        // Direction has no superuser flag: its reach comes purely from managing the top unit.
        $directionRole = (new Role())->setCode('direction')->setName('Dirección');
        $this->em->persist($directionRole);

        $director = (new User())->setFullName('Ana Directora')->setEmail('director@centro.test')->addAssignedRole($directionRole);
        $teacher = (new User())->setFullName('Pedro Docente')->setEmail('profe@centro.test');
        $colleague = (new User())->setFullName('Sara Colega')->setEmail('colega@centro.test');
        array_map($this->em->persist(...), [$director, $teacher, $colleague]);

        $management = (new Unit())->setCode('mgmt')->setName('Dirección')->setManager($director);
        $maths = (new Unit())->setCode('maths')->setName('Matemáticas')->setParent($management);
        array_map($this->em->persist(...), [$management, $maths]);
        $director->setUnit($management);
        $teacher->setUnit($maths);
        $colleague->setUnit($maths);

        $year = SchoolYear::current(new \DateTimeImmutable());
        $mineTitle = 'Preparar el acta del docente';
        $colleagueTitle = 'Memoria de la colega';
        $mine = (new Task($mineTitle, $year, new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE))->setUnit($maths)->setAssignedUser($teacher);
        $colleagueTask = (new Task($colleagueTitle, $year, new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE))->setUnit($maths)->setAssignedUser($colleague);
        array_map($this->em->persist(...), [$mine, $colleagueTask]);
        $this->em->flush();

        return ['director' => $director, 'teacher' => $teacher, 'mineTitle' => $mineTitle, 'colleagueTitle' => $colleagueTitle, 'colleagueTask' => $colleagueTask];
    }

    public function testTeacherSeesOnlyTheirOwnTasks(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['teacher']);

        $this->client->request('GET', '/tareas');
        $content = (string) $this->client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($s['mineTitle'], $content, 'the teacher sees the task assigned to them');
        self::assertStringNotContainsString($s['colleagueTitle'], $content, "the teacher does not see a colleague's task");
    }

    public function testDirectorSeesEveryTaskThroughTheHierarchy(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $this->client->request('GET', '/tareas');
        $content = (string) $this->client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($s['mineTitle'], $content);
        self::assertStringContainsString($s['colleagueTitle'], $content, 'the top manager sees tasks across the whole subtree');
    }

    public function testTeacherIsForbiddenFromAColleaguesTaskDetail(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['teacher']);

        // The detail is scoped like the plan: a task outside the teacher's own/managed set is a 403,
        // not readable by guessing its id.
        $this->client->request('GET', '/tareas/'.$s['colleagueTask']->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testDirectorReachesAColleaguesTaskDetailThroughTheHierarchy(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['director']);

        $this->client->request('GET', '/tareas/'.$s['colleagueTask']->getId());

        self::assertResponseIsSuccessful();
    }

    public function testTeacherSeesTaskAssignedToARoleTheyHold(): void
    {
        // A task can be assigned to a role rather than a person: whoever holds that role owns it.
        $role = (new Role())->setCode('ccp')->setName('Coordinación pedagógica');
        $this->em->persist($role);
        $teacher = (new User())->setFullName('Pedro Docente')->setEmail('profe@centro.test')->addAssignedRole($role);
        $this->em->persist($teacher);
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);

        $year = SchoolYear::current(new \DateTimeImmutable());
        $title = 'Acta de la CCP';
        $task = (new Task($title, $year, new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE))->setUnit($unit)->setAssignedRole($role);
        $this->em->persist($task);
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/tareas');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($title, (string) $this->client->getResponse()->getContent(), 'a role-assigned task is the holder\'s own');
    }
}
