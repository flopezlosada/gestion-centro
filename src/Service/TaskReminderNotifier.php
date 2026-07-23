<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;

/**
 * The reminder engine. Run daily (see {@see \App\Command\SendTaskRemindersCommand}), it:
 *
 *  - reminds the assignee of tasks due in 15 and 7 days (in-app notice + e-mail + push);
 *  - escalates tasks that are overdue and still open up the chain of command (the immediate
 *    superior after 1 day, the whole chain after 7).
 *
 * Idempotent by design without any "already notified" flag: every offset matches an EXACT day
 * ({@see TaskRepository::findOpenDueOn()}), so a given (task, offset) fires on one single run.
 */
final class TaskReminderNotifier
{
    /** Places where the assignee still has to act (Pendiente): útil recordarle la fecha. Una Entregada
     * espera al superior, no al responsable. */
    private const array ASSIGNEE_OPEN = ['pending'];

    /** Places that are not closed yet (Pendiente o Entregada), for escalation. Finalizada y Cancelada
     * son cierres. */
    private const array NOT_CLOSED = ['pending', 'submitted'];

    /** Days before the deadline to remind the assignee. */
    private const array REMIND_BEFORE_DAYS = [15, 7];

    /** Days after the deadline to escalate up the chain. */
    private const array ESCALATE_AFTER_DAYS = [1, 7];

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly UserRepository $users,
        private readonly OrganizationHierarchy $hierarchy,
        private readonly NotificationDispatcher $dispatcher,
    ) {
    }

    /**
     * Creates and sends every reminder/escalation due on the given day. In-app notices are persisted
     * first (a single flush), then e-mails and push are sent, so a delivery failure never loses the
     * in-app notice.
     *
     * @param \DateTimeImmutable $today the reference day (time is ignored)
     *
     * @return int the number of notifications created
     */
    public function sendDue(\DateTimeImmutable $today): int
    {
        $today = $today->setTime(0, 0);
        /** @var list<Notification> $notifications */
        $notifications = [];

        foreach (self::REMIND_BEFORE_DAYS as $days) {
            $due = $today->modify(sprintf('+%d days', $days));
            foreach ($this->tasks->findOpenDueOn($due, self::ASSIGNEE_OPEN) as $task) {
                foreach ($this->assigneeRecipients($task) as $recipient) {
                    $notifications[] = $this->dispatcher->record(
                        $recipient,
                        'task.reminder',
                        sprintf('Tarea próxima: %s', $task->getTitle()),
                        sprintf('Vence el %s (en %d días).', $task->getDueDate()->format('d/m/Y'), $days),
                        $task,
                    );
                }
            }
        }

        foreach (self::ESCALATE_AFTER_DAYS as $days) {
            $due = $today->modify(sprintf('-%d days', $days));
            foreach ($this->tasks->findOpenDueOn($due, self::NOT_CLOSED) as $task) {
                foreach ($this->escalationRecipients($task, $days) as $recipient) {
                    $notifications[] = $this->dispatcher->record(
                        $recipient,
                        'task.escalation',
                        sprintf('Tarea vencida sin cerrar: %s', $task->getTitle()),
                        sprintf('Venció el %s (hace %d días) y sigue sin cerrarse.', $task->getDueDate()->format('d/m/Y'), $days),
                        $task,
                    );
                }
            }
        }

        // One flush for the whole batch, then deliver each over e-mail + push (best-effort per notice).
        $this->dispatcher->flushAndSend($notifications);

        return \count($notifications);
    }

    /**
     * The people who must act on a task: the assigned person, or everyone holding the assigned role.
     *
     * @param Task $task the task
     *
     * @return list<User> the recipients (may be empty if the task is unassigned)
     */
    private function assigneeRecipients(Task $task): array
    {
        if (null !== $task->getAssignedUser()) {
            return [$task->getAssignedUser()];
        }

        if (null !== $task->getAssignedRole()) {
            return array_values($this->users->findActiveByRole($task->getAssignedRole()));
        }

        return [];
    }

    /**
     * The superiors to escalate an overdue task to: the immediate manager after 1 day, the whole
     * chain of command after a week.
     *
     * @param Task $task the overdue task
     * @param int  $days how many days it has been overdue
     *
     * @return list<User> the superiors to notify, nearest first
     */
    private function escalationRecipients(Task $task, int $days): array
    {
        $chain = $this->hierarchy->managersAbove($task);
        // Escalating a task to its own assignee is pointless (they are the one who is late).
        $chain = array_values(array_filter($chain, static fn (User $m): bool => $m !== $task->getAssignedUser()));

        return $days >= 7 ? $chain : \array_slice($chain, 0, 1);
    }
}
