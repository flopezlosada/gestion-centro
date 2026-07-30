<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\Department;
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
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');

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

    /**
     * Separation of duties is measured against whoever HOLDS the task now, not against the titular: on a
     * delegated task the work is the delegatee's, so they must not sign off their own delivery — even
     * though the assignee column still points at the person who handed it over.
     */
    public function testDelegateeCannotValidateTheirOwnWork(): void
    {
        $client = static::createClient();
        $s = $this->scenario();
        // The head of Maths' own task, delegated down to the teacher, who has just submitted it.
        $s['task']->setAssignedUser($s['headMaths'])->setDelegatedTo($s['teacher']);
        $client->loginUser($s['teacher']);

        self::assertFalse($this->canValidate($client, $s['task']), 'el delegado no valida su propio trabajo');
    }

    /**
     * The mirror case: the titular who delegated the work DOES judge what the delegatee delivered. By
     * rank alone they would never qualify (nobody outranks themselves), yet it is their own task and
     * they stay accountable for it.
     */
    public function testTitularWhoDelegatedCanValidateTheDelegateesWork(): void
    {
        $client = static::createClient();
        $s = $this->scenario();
        $s['task']->setAssignedUser($s['headMaths'])->setDelegatedTo($s['teacher']);
        $client->loginUser($s['headMaths']);

        self::assertTrue($this->canValidate($client, $s['task']), 'quien delegó juzga lo que entregó su delegado');
        self::assertTrue($this->canReject($s['task']), 'y también puede devolvérselo');
    }

    /**
     * A superior may close a task that is still Pendiente ("Dar por finalizada"), for the work that got
     * done outside the app or by someone who cannot deliver it: otherwise the only way out was Cancelar,
     * which records it as void and is terminal.
     */
    public function testSuperiorCanCloseAPendingTask(): void
    {
        $client = static::createClient();
        $s = $this->scenario();
        $s['task']->setStatus('pending');
        $client->loginUser($s['headStudies']);

        self::assertTrue($this->canValidate($client, $s['task']), 'un superior puede dar por finalizada una pendiente');
    }

    /** The same door does NOT open for the person who owes the work: that would be self-validation. */
    public function testAssigneeCannotCloseTheirOwnPendingTask(): void
    {
        $client = static::createClient();
        $s = $this->scenario();
        $s['task']->setStatus('pending');
        $client->loginUser($s['teacher']);

        self::assertFalse($this->canValidate($client, $s['task']), 'el responsable no se cierra su propia tarea');
    }

    /** "Devolver" still needs something delivered: there is nothing to send back from Pendiente. */
    public function testRejectIsNotAvailableFromPending(): void
    {
        $client = static::createClient();
        $s = $this->scenario();
        $s['task']->setStatus('pending');
        $client->loginUser($s['headStudies']);

        self::assertFalse($this->canReject($s['task']), 'no se devuelve algo que nadie ha entregado');
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
