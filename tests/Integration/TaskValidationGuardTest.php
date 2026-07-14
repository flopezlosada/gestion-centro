<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\TaskType;
use App\Service\TaskWorkflow;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The "validate" transition must be allowed only for a superior of the task's unit (up the chain of
 * command) or an admin, and never for the task's own assignee. Each case authenticates a real actor.
 */
final class TaskValidationGuardTest extends WebTestCase
{
    private function user(string $email): User
    {
        return (new User())->setFullName($email)->setEmail($email.'@example.test');
    }

    /**
     * The head of studies (centre-wide ranked role), the head of the Maths department (per-department
     * ranked role) and a plain teacher, plus a submitted deliverable task in Maths assigned to the
     * teacher. Superiority is derived from the roles, in memory (no database needed).
     *
     * @return array{task: Task, headStudies: User, headMaths: User, teacher: User}
     */
    private function scenario(): array
    {
        $maths = (new Unit())->setCode('maths')->setName('Matemáticas');

        $headStudies = $this->user('jefatura')
            ->addAssignedRole((new Role())->setCode('head_of_studies')->setName('Jefatura de estudios')->setHierarchyLevel(30));
        $headMaths = $this->user('mates')->setUnit($maths)
            ->addAssignedRole((new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10));
        $teacher = $this->user('docente')->setUnit($maths);

        $task = new Task('Memoria', '2025-2026', new \DateTimeImmutable('2026-05-31'), TaskType::WITH_DELIVERABLE);
        $task->setUnit($maths)->setAssignedUser($teacher)->setStatus('submitted');

        return ['task' => $task, 'headStudies' => $headStudies, 'headMaths' => $headMaths, 'teacher' => $teacher];
    }

    private function canValidate(KernelBrowser $client, Task $task): bool
    {
        /** @var TaskWorkflow $workflows */
        $workflows = self::getContainer()->get('test.task_workflow');

        return $workflows->for($task)->can($task, 'validate');
    }

    public function testSuperiorUpTheChainCanValidate(): void
    {
        $client = static::createClient();
        $s = $this->scenario();
        $client->loginUser($s['headStudies']);

        self::assertTrue($this->canValidate($client, $s['task']));
    }

    public function testAdminCanValidateEvenOutsideTheChain(): void
    {
        $client = static::createClient();
        $s = $this->scenario();
        $admin = $this->user('admin');
        $admin->addAssignedRole((new Role())->setCode('admin')->setName('Administración')->setAdmin(true));
        $client->loginUser($admin);

        self::assertTrue($this->canValidate($client, $s['task']));
    }

    public function testOutsiderCannotValidate(): void
    {
        $client = static::createClient();
        $s = $this->scenario();
        $client->loginUser($this->user('otro'));

        self::assertFalse($this->canValidate($client, $s['task']));
    }

    private function canReject(Task $task): bool
    {
        /** @var TaskWorkflow $workflows */
        $workflows = self::getContainer()->get('test.task_workflow');

        return $workflows->for($task)->can($task, 'reject');
    }

    public function testSuperiorCanReject(): void
    {
        $client = static::createClient();
        $s = $this->scenario();
        $client->loginUser($s['headStudies']);

        self::assertTrue($this->canReject($s['task']), 'un superior puede devolver la tarea');
    }

    public function testOutsiderCannotReject(): void
    {
        $client = static::createClient();
        $s = $this->scenario();
        $client->loginUser($this->user('otro'));

        self::assertFalse($this->canReject($s['task']), 'devolver es acción de superior, no de cualquiera');
    }

    public function testAssigneeCannotValidateOwnTaskEvenIfSuperior(): void
    {
        $client = static::createClient();
        $s = $this->scenario();
        // headMaths outranks the maths task, but here it is assigned to headMaths: separation of duties
        // wins over rank — you never validate your own task.
        $s['task']->setAssignedUser($s['headMaths']);
        $client->loginUser($s['headMaths']);

        self::assertFalse($this->canValidate($client, $s['task']), 'no self-validation, even for a superior by rank');
    }

    public function testCentreWideSuperiorCanValidateEvenWithoutUnit(): void
    {
        $client = static::createClient();
        $s = $this->scenario();
        // A unit-less task still falls under a centre-wide superior (dirección/jefatura de estudios),
        // who oversees the whole school.
        $s['task']->setUnit(null);
        $client->loginUser($s['headStudies']);

        self::assertTrue($this->canValidate($client, $s['task']), 'a centre-wide superior oversees even a unit-less task');
    }
}
