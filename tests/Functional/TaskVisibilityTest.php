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
 * The task list is of universal access but filtered by the chain of command: a teacher sees only
 * their own tasks, a department head their department's, and Direction/admins every task.
 */
final class TaskVisibilityTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Seeds two departments with a task each and returns the actors needed by the tests.
     *
     * @return array{teacher: User, mathsHead: User, admin: User}
     */
    private function seed(): array
    {
        $mathsHead = (new User())->setFullName('María Matemáticas')->setEmail('mates@centro.test');
        $langHead = (new User())->setFullName('Lucía Lengua')->setEmail('lengua@centro.test');
        $teacher = (new User())->setFullName('Pedro Docente')->setEmail('profe@centro.test');
        $admin = (new User())->setFullName('Tomás TIC')->setEmail('tic@centro.test')
            ->addAssignedRole((new Role())->setCode('tic')->setName('TIC')->setAdmin(true));

        $maths = (new Unit())->setCode('maths')->setName('Departamento de Matemáticas')->setManager($mathsHead);
        $lang = (new Unit())->setCode('lengua')->setName('Departamento de Lengua')->setManager($langHead);
        $teacher->setUnit($maths);
        $mathsHead->setUnit($maths);
        $langHead->setUnit($lang);

        $year = SchoolYear::current(new \DateTimeImmutable());
        $mathsTask = (new Task('Tarea de Matemáticas', $year, new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE))
            ->setUnit($maths)->setAssignedUser($teacher);
        $langTask = (new Task('Tarea de Lengua', $year, new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE))
            ->setUnit($lang)->setAssignedUser($langHead);

        array_map($this->em->persist(...), [$mathsHead, $langHead, $teacher, $admin, $maths, $lang, $mathsTask, $langTask]);
        $this->em->flush();

        return ['teacher' => $teacher, 'mathsHead' => $mathsHead, 'admin' => $admin];
    }

    public function testTeacherSeesOnlyTheirOwnTasks(): void
    {
        $actors = $this->seed();
        $this->client->loginUser($actors['teacher']);

        $this->client->request('GET', '/tareas');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Tarea de Matemáticas');
        self::assertStringNotContainsString('Tarea de Lengua', (string) $this->client->getResponse()->getContent());
    }

    public function testDepartmentHeadSeesTheirDepartmentTasks(): void
    {
        $actors = $this->seed();
        $this->client->loginUser($actors['mathsHead']);

        $this->client->request('GET', '/tareas');

        self::assertResponseIsSuccessful();
        // The head is a superior of the Maths unit (its manager), so its tasks are visible…
        self::assertSelectorTextContains('table', 'Tarea de Matemáticas');
        // …but not another department's.
        self::assertStringNotContainsString('Tarea de Lengua', (string) $this->client->getResponse()->getContent());
    }

    public function testDirectionSeesEveryTask(): void
    {
        $actors = $this->seed();
        $this->client->loginUser($actors['admin']);

        $this->client->request('GET', '/tareas');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Tarea de Matemáticas', $content);
        self::assertStringContainsString('Tarea de Lengua', $content);
    }
}
