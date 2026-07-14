<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskType;
use App\EventSubscriber\TaskCompletionSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Event\EnteredEvent;
use Symfony\Component\Workflow\Marking;

/**
 * The completion subscriber freezes who did a task the moment it reaches "validated", once and for
 * all: a later change of holder must never rewrite that historical fact.
 */
final class TaskCompletionSubscriberTest extends TestCase
{
    private function task(): Task
    {
        return new Task('Memoria final', '2025-2026', new \DateTimeImmutable('2026-05-31'), TaskType::SIMPLE);
    }

    private function user(string $name): User
    {
        return (new User())->setFullName($name)->setEmail(strtolower($name).'@centro.test');
    }

    private function entering(Task $task, string $place): EnteredEvent
    {
        return new EnteredEvent($task, new Marking([$place => 1]), null, null);
    }

    public function testFreezesTheAssigneeWhenReachingValidated(): void
    {
        $ana = $this->user('Ana');
        $task = $this->task()->setAssignedUser($ana);

        (new TaskCompletionSubscriber())($this->entering($task, 'validated'));

        self::assertSame($ana, $task->getCompletedBy());
    }

    public function testTheDelegateeIsFrozenOverTheAssignee(): void
    {
        $ana = $this->user('Ana');
        $beto = $this->user('Beto');
        $task = $this->task()->setAssignedUser($ana)->setDelegatedTo($beto);

        (new TaskCompletionSubscriber())($this->entering($task, 'validated'));

        self::assertSame($beto, $task->getCompletedBy(), 'the person who actually did it is the delegatee');
    }

    public function testDoesNothingWhenNotReachingValidated(): void
    {
        $task = $this->task()->setAssignedUser($this->user('Ana'));

        (new TaskCompletionSubscriber())($this->entering($task, 'submitted'));

        self::assertNull($task->getCompletedBy());
    }

    public function testFreezesOnlyOnceEvenIfTheHolderChangesLater(): void
    {
        $first = $this->user('Ana');
        $task = $this->task()->setAssignedUser($first);
        $subscriber = new TaskCompletionSubscriber();

        $subscriber($this->entering($task, 'validated'));
        // The holder changes after the task was already closed; the frozen fact must not move.
        $task->setAssignedUser($this->user('Beto'));
        $subscriber($this->entering($task, 'validated'));

        self::assertSame($first, $task->getCompletedBy());
    }
}
