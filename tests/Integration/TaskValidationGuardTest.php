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
     * Builds management → head of studies → maths, a submitted deliverable task in maths assigned to
     * a plain teacher, and returns everything the cases need.
     *
     * @return array{task: Task, headStudies: User, headMaths: User, teacher: User}
     */
    private function scenario(): array
    {
        $headStudies = $this->user('jefatura');
        $headMaths = $this->user('mates');
        $teacher = $this->user('docente');

        $management = (new Unit())->setCode('mgmt')->setName('Dirección');
        $studies = (new Unit())->setCode('studies')->setName('Jefatura de estudios')->setManager($headStudies)->setParent($management);
        $maths = (new Unit())->setCode('maths')->setName('Matemáticas')->setManager($headMaths)->setParent($studies);

        $task = new Task('Memoria', '2025-2026', new \DateTimeImmutable('2026-05-31'), TaskType::WITH_DELIVERABLE);
        $task->setUnit($maths)->setAssignedUser($teacher)->setStatus('submitted');

        return ['task' => $task, 'headStudies' => $headStudies, 'headMaths' => $headMaths, 'teacher' => $teacher];
    }

    private function canValidate(KernelBrowser $client, Task $task): bool
    {
        return self::getContainer()->get(TaskWorkflow::class)->for($task)->can($task, 'validate');
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

    public function testAssigneeCannotValidateOwnTaskEvenIfManager(): void
    {
        $client = static::createClient();
        $s = $this->scenario();
        // headMaths manages the maths unit, but the task is assigned to headMaths here.
        $s['task']->setAssignedUser($s['headMaths']);
        $client->loginUser($s['headMaths']);

        self::assertFalse($this->canValidate($client, $s['task']), 'no self-validation, even for the unit manager');
    }

    public function testCannotValidateWhenTaskHasNoUnit(): void
    {
        $client = static::createClient();
        $s = $this->scenario();
        $s['task']->setUnit(null);
        $client->loginUser($s['headStudies']);

        self::assertFalse($this->canValidate($client, $s['task']), 'no unit → no determinable superior');
    }
}
