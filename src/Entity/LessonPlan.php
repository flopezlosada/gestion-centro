<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EducationLevel;
use App\Enum\LessonActivity;
use App\Enum\LessonOutcome;
use App\Repository\LessonPlanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a teacher plans for ONE of their classes — a day and a period — and, afterwards, how it went:
 * the topic, what the class is mostly about, and whether it was done. Filled with taps, not typed: the
 * topic comes from the shared list, the activity and the outcome are chips, and the only free text is a
 * short optional line ("pág. 52, ej. 1-8").
 *
 * PRIVATE to the teacher. Nobody else reads it, the admin included: it is a teacher's own working
 * notebook, and a tool that others can read into is a tool people stop being honest in. Opening it up to
 * a role later is possible, but it would expose what was written as private, so that has to be decided
 * for what is written from then on.
 *
 * The class is identified by teacher, day and period (one plan per class) and remembers its groups and
 * subject as the timetable had them, which is what "the previous class with this group" looks for.
 */
#[ORM\Entity(repositoryClass: LessonPlanRepository::class)]
#[ORM\Table(name: 'lesson_plan')]
#[ORM\UniqueConstraint(name: 'uniq_lesson_plan_class', columns: ['teacher_id', 'lesson_date', 'slot_index'])]
#[ORM\Index(name: 'idx_lesson_plan_continuity', columns: ['teacher_id', 'subject', 'group_names'])]
class LessonPlan
{
    /** Longest free line kept: a page and some exercises, not a lesson plan in prose. */
    public const int MAX_NOTE = 160;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $teacher;

    #[ORM\Column(name: 'lesson_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(name: 'slot_index', type: Types::SMALLINT)]
    private int $slotIndex;

    /** The class's groups as shown ("B1A, B1B"): with the subject, what makes two classes "the same". */
    #[ORM\Column(name: 'group_names', length: 160)]
    private string $groupNames;

    #[ORM\Column(length: 120)]
    private string $subject;

    #[ORM\Column(length: 16, nullable: true, enumType: EducationLevel::class)]
    private ?EducationLevel $level;

    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Topic $topic = null;

    #[ORM\Column(length: 16, nullable: true, enumType: LessonActivity::class)]
    private ?LessonActivity $activity = null;

    #[ORM\Column(length: 16, nullable: true, enumType: LessonOutcome::class)]
    private ?LessonOutcome $outcome = null;

    #[ORM\Column(length: self::MAX_NOTE, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param User                $teacher    whose class it is
     * @param \DateTimeImmutable  $date       the day
     * @param int                 $slotIndex  the period
     * @param string              $groupNames the class's groups as shown
     * @param string              $subject    the subject
     * @param EducationLevel|null $level      the level, when recognised
     */
    public function __construct(User $teacher, \DateTimeImmutable $date, int $slotIndex, string $groupNames, string $subject, ?EducationLevel $level)
    {
        $this->teacher = $teacher;
        $this->date = $date->setTime(0, 0);
        $this->slotIndex = $slotIndex;
        $this->groupNames = mb_substr($groupNames, 0, 160);
        $this->subject = mb_substr($subject, 0, 120);
        $this->level = $level;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Writes what the teacher planned and, if they said, how it went.
     *
     * @param Topic|null          $topic    the topic, or none
     * @param LessonActivity|null $activity what the class is about, or unsaid
     * @param LessonOutcome|null  $outcome  how it went, or not told yet
     * @param string|null         $note     the short free line
     */
    public function plan(?Topic $topic, ?LessonActivity $activity, ?LessonOutcome $outcome, ?string $note): static
    {
        $this->topic = $topic;
        $this->activity = $activity;
        $this->outcome = $outcome;
        $note = null !== $note ? trim($note) : '';
        $this->note = '' !== $note ? mb_substr($note, 0, self::MAX_NOTE) : null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Whether nothing at all was planned: an empty plan is not worth keeping.
     */
    public function isEmpty(): bool
    {
        return null === $this->topic && null === $this->activity && null === $this->outcome && null === $this->note;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeacher(): User
    {
        return $this->teacher;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getSlotIndex(): int
    {
        return $this->slotIndex;
    }

    public function getGroupNames(): string
    {
        return $this->groupNames;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getLevel(): ?EducationLevel
    {
        return $this->level;
    }

    public function getTopic(): ?Topic
    {
        return $this->topic;
    }

    public function getActivity(): ?LessonActivity
    {
        return $this->activity;
    }

    public function getOutcome(): ?LessonOutcome
    {
        return $this->outcome;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }
}
