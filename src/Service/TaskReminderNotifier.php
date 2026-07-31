<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\NotificationRepository;
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

    /** The kind shared by the nightly reminder and the manual nudge (see {@see nudge()}). */
    public const string REMINDER_KIND = 'task.reminder';

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly UserRepository $users,
        private readonly OrganizationHierarchy $hierarchy,
        private readonly NotificationDispatcher $dispatcher,
        private readonly NotificationRepository $notifications,
    ) {
    }

    /**
     * Nudges whoever has to do the task, on demand ("Recordar" on the task page), with the same notice
     * the nightly engine sends. It exists because the automatic reminders only fire on four exact days
     * (15 and 7 before the deadline, then escalation to the superiors 1 and 7 after): once a task is
     * overdue — or was created with less than a week of margin — the person responsible never hears
     * about it again, and there was no way to tell them.
     *
     * AT MOST ONE per person and day, counting the automatic ones too (both share
     * {@see REMINDER_KIND}): a button that can be pressed ten times is ten e-mails and ten push
     * notifications to the same person, which stops being a reminder.
     *
     * "Today" is deliberately NOT a parameter: {@see \App\Entity\Notification} stamps its own
     * createdAt from the system clock, so a caller-supplied "now" would be a second clock to
     * disagree with — and it did, silently letting a second nudge through.
     *
     * It reaches ONE person: the responsible the screen shows ({@see Task::resolveResponsible()}), not
     * every holder of the task's role like the nightly sweep does. Whoever presses the button is looking
     * at a name; on a task assigned to a broad role ("Docente" has 78 holders) fanning out would put an
     * e-mail and a push to the whole staff behind a single click.
     *
     * @param Task $task the task to nudge about
     *
     * @return User|null the person notified, or null when they were already told today / there is nobody
     */
    public function nudge(Task $task): ?User
    {
        // Nadie tiene que hacer una tarea ya cerrada. Se comprueba AQUÍ y no solo en el controlador para
        // que el servicio no dependa de que su llamador se acuerde.
        $recipient = $this->nudgeRecipient($task);
        if (null === $recipient || null !== $this->lastRemindedAt($task, $recipient)) {
            return null;
        }

        $this->dispatcher->dispatch(
            $recipient,
            self::REMINDER_KIND,
            sprintf('Recordatorio: %s', $task->getTitle()),
            // Sirve igual antes y después de la fecha: el aviso manual se manda sobre todo con la
            // tarea ya vencida, y un "vence el" en pasado se lee como un error de la aplicación.
            sprintf('Sigue pendiente. Fecha límite: %s.', $task->getDueDate()->format('d/m/Y')),
            $task,
        );

        return $recipient;
    }

    /**
     * When the person was last reminded about the task TODAY, or null if not yet today. Lets the task
     * page show "Avisado hoy a las 13:40" instead of a button that would do nothing. Reads the same
     * clock the notices are stamped with, so the page and the endpoint can never disagree.
     *
     * @param Task $task      the task
     * @param User $recipient the person
     *
     * @return \DateTimeImmutable|null the moment of today's last reminder, or null
     */
    public function lastRemindedAt(Task $task, User $recipient): ?\DateTimeImmutable
    {
        $sentAt = $this->notifications->findLatestAbout($recipient, $task, self::REMINDER_KIND)?->getCreatedAt();
        $today = (new \DateTimeImmutable())->format('Y-m-d');

        return null !== $sentAt && $sentAt->format('Y-m-d') === $today ? $sentAt : null;
    }

    /**
     * Who a manual nudge would reach: the responsible the screen shows, and only while the task is still
     * open. Null when the task is closed or has nobody on the hook — which the caller must tell apart
     * from "already nudged today".
     *
     * @param Task $task the task
     *
     * @return User|null the person to nudge, or null
     */
    public function nudgeRecipient(Task $task): ?User
    {
        return $task->isClosed() ? null : $task->resolveResponsible();
    }

    /**
     * When the task's responsible was already reminded today — that is, when a nudge would reach nobody
     * new. Null while there is still someone to tell (or nobody to tell at all). The task page uses it to
     * replace the button with "Avisado hoy a las 13:40", so what it offers matches what the endpoint
     * would actually do.
     *
     * @param Task $task the task
     *
     * @return \DateTimeImmutable|null today's reminder, or null if a nudge would still reach someone
     */
    public function nudgedTodayAt(Task $task): ?\DateTimeImmutable
    {
        $recipient = $this->nudgeRecipient($task);

        return null !== $recipient ? $this->lastRemindedAt($task, $recipient) : null;
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
                        self::REMINDER_KIND,
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
                        sprintf('Tarea fuera de plazo sin cerrar: %s', $task->getTitle()),
                        sprintf('El plazo terminó el %s (hace %d días) y sigue sin cerrarse.', $task->getDueDate()->format('d/m/Y'), $days),
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
        // A delegated task is the DELEGATEE's to do: reminding the titular who handed it over (what
        // getAssignedUser() returns) told the wrong person their deadline was coming.
        if (null !== $task->getDelegatedTo()) {
            return [$task->getDelegatedTo()];
        }

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
        // Escalating a task to whoever has to do it is pointless (they are the one who is late). Uses
        // isOwnedBy, so on a delegated task the escalation reaches the titular who delegated it — they
        // stay accountable — but not the delegatee who is already late.
        $chain = array_values(array_filter($chain, static fn (User $m): bool => !$task->isOwnedBy($m)));

        return $days >= 7 ? $chain : \array_slice($chain, 0, 1);
    }
}
