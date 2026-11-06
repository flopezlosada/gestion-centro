<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TaskReminderRepeat;
use App\Enum\TaskReminderStart;
use App\Repository\TaskReminderScheduleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * When ONE person wants to be reminded about ONE task they have to do: how many days ahead the first
 * reminder goes and how often it repeats. Chosen by whoever receives the reminder, never by whoever
 * created the task — a task sent to a whole department is still each person's own diary.
 *
 * The row exists only when the person changed something: no row means {@see DEFAULT_DAYS_BEFORE}, the
 * reminders everybody had before this was configurable. Going back to "lo de siempre" deletes the row,
 * so there is a single way of saying "default".
 */
#[ORM\Entity(repositoryClass: TaskReminderScheduleRepository::class)]
#[ORM\Table(name: 'task_reminder_schedule')]
#[ORM\UniqueConstraint(name: 'uniq_task_reminder_schedule', columns: ['task_id', 'user_id'])]
class TaskReminderSchedule
{
    /** Days before the deadline the reminder goes when the person never chose anything. */
    public const array DEFAULT_DAYS_BEFORE = [15, 7];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'start_days', type: Types::SMALLINT, enumType: TaskReminderStart::class)]
    private TaskReminderStart $start;

    #[ORM\Column(name: 'repeat_days', type: Types::SMALLINT, enumType: TaskReminderRepeat::class)]
    private TaskReminderRepeat $repeat;

    /**
     * @param Task               $task   the task the reminders are about
     * @param User               $user   who receives them (and chose them)
     * @param TaskReminderStart  $start  how many days ahead the first one goes
     * @param TaskReminderRepeat $repeat how often it repeats afterwards
     */
    public function __construct(Task $task, User $user, TaskReminderStart $start, TaskReminderRepeat $repeat)
    {
        $this->task = $task;
        $this->user = $user;
        $this->start = $start;
        $this->repeat = $repeat;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStart(): TaskReminderStart
    {
        return $this->start;
    }

    public function getRepeat(): TaskReminderRepeat
    {
        return $this->repeat;
    }

    /**
     * Changes the schedule in place (the person edits it again).
     *
     * @param TaskReminderStart  $start  how many days ahead the first one goes
     * @param TaskReminderRepeat $repeat how often it repeats afterwards
     */
    public function change(TaskReminderStart $start, TaskReminderRepeat $repeat): static
    {
        $this->start = $start;
        $this->repeat = $repeat;

        return $this;
    }

    /**
     * The days before the deadline a reminder goes, furthest first: the start, then every repeat gap
     * down to the day before the deadline (never on it, nor after — overdue is escalation's job).
     *
     * @return list<int> e.g. [5, 3, 1] for "5 días antes, cada 2 días"
     */
    public function daysBefore(): array
    {
        if (TaskReminderRepeat::ONCE === $this->repeat) {
            return [$this->start->value];
        }

        // Not range(): it throws when the step is not smaller than the span ("2 días antes, cada 2
        // días" is a perfectly good choice that would just be [2]), and one bad row would stop the
        // nightly sweep for the whole centre.
        $days = [];
        for ($day = $this->start->value; $day >= 1; $day -= $this->repeat->value) {
            $days[] = $day;
        }

        return $days;
    }

    /**
     * The days before the deadline a person gets reminded, with or without a schedule of their own.
     *
     * @param self|null $schedule what they chose, or null if they never did
     *
     * @return list<int> the days before the deadline, furthest first
     */
    public static function daysBeforeFor(?self $schedule): array
    {
        return $schedule?->daysBefore() ?? self::DEFAULT_DAYS_BEFORE;
    }

    /**
     * The calendar days a person gets reminded of a task, earliest first: the same days the nightly
     * sweep uses ({@see daysBeforeFor()}), turned into dates so the screen can show them as such.
     *
     * @param self|null          $schedule what they chose, or null if they never did
     * @param \DateTimeImmutable $dueDate  the task's deadline
     *
     * @return list<\DateTimeImmutable> the reminder dates, earliest first
     */
    public static function datesFor(?self $schedule, \DateTimeImmutable $dueDate): array
    {
        return array_map(
            static fn (int $days): \DateTimeImmutable => $dueDate->setTime(0, 0)->modify(sprintf('-%d days', $days)),
            self::daysBeforeFor($schedule),
        );
    }

    /**
     * The default in words, for whoever chooses: built from {@see DEFAULT_DAYS_BEFORE} so the screen
     * can never promise something else than what the sweep does.
     *
     * @return string e.g. "15 y 7 días antes"
     */
    public static function defaultLabel(): string
    {
        return implode(' y ', self::DEFAULT_DAYS_BEFORE).' días antes';
    }
}
