<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EducationLevel;
use App\Enum\LessonActivity;
use App\Enum\LessonOutcome;
use App\Repository\LessonPlanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a teacher plans for ONE of their classes — a day and a period — and, afterwards, how it went:
 * its topic (or topics: {@see LessonPlanTopic}), what the class is mostly about, and whether it was
 * done. Filled with taps, not typed: the topic comes from the shared list, the activity and the outcome
 * are chips, and the only free text is a short optional line ("pág. 52, ej. 1-8").
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
    /** Column widths of the class's groups and subject: what a stored plan is cut to and compared at. */
    private const int MAX_GROUPS = 160;
    private const int MAX_SUBJECT = 120;
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

    /**
     * @var Collection<int, LessonPlanTopic> ordered 0 (the topic the class closed, or its only one), then
     *                                        1 (the one it opened) — {@see LessonPlanTopic}
     */
    #[ORM\OneToMany(mappedBy: 'lessonPlan', targetEntity: LessonPlanTopic::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $topics;

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
        $this->groupNames = mb_substr($groupNames, 0, self::MAX_GROUPS);
        $this->subject = mb_substr($subject, 0, self::MAX_SUBJECT);
        $this->level = $level;
        $this->topics = new ArrayCollection();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Writes what the teacher planned for a class with a single topic (the ordinary case) and, if they
     * said, how it went.
     *
     * @param Topic|null          $topic    the topic, or none
     * @param LessonActivity|null $activity what the class is about, or unsaid
     * @param LessonOutcome|null  $outcome  how it went, or not told yet
     * @param string|null         $note     the short free line
     */
    public function plan(?Topic $topic, ?LessonActivity $activity, ?LessonOutcome $outcome, ?string $note): static
    {
        return $this->planTwo($topic, $activity, $outcome, null, null, null, $note);
    }

    /**
     * Writes what the teacher planned for a class that closed one topic and opened another — half a class
     * for each, for whoever later counts how many classes a topic took.
     *
     * @param Topic|null          $topic    the topic the class closed (or its only one), or none
     * @param LessonActivity|null $activity what the class did with it, or unsaid
     * @param LessonOutcome|null  $outcome  how it went, or not told yet
     * @param Topic|null          $topic2   the topic the class opened, or none
     * @param LessonActivity|null $activity2 what the class did with it, or unsaid
     * @param LessonOutcome|null  $outcome2  how it went, or not told yet
     * @param string|null         $note      the short free line
     */
    public function planTwo(
        ?Topic $topic,
        ?LessonActivity $activity,
        ?LessonOutcome $outcome,
        ?Topic $topic2,
        ?LessonActivity $activity2,
        ?LessonOutcome $outcome2,
        ?string $note,
    ): static {
        $tuples = array_values(array_filter(
            [[$topic, $activity, $outcome], [$topic2, $activity2, $outcome2]],
            static fn (array $t): bool => null !== $t[0] || null !== $t[1] || null !== $t[2],
        ));
        $this->replaceTopics($tuples);
        $note = null !== $note ? trim($note) : '';
        $this->note = '' !== $note ? mb_substr($note, 0, self::MAX_NOTE) : null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Whether a class with these groups and subject is the same class as this one, compared the way this
     * plan stored them (cut to the column width) — a long list of groups compared uncut would never match.
     *
     * @param string $groupNames the class's groups as shown
     * @param string $subject    the class's subject
     */
    public function isSameClassAs(string $groupNames, string $subject): bool
    {
        return mb_substr($groupNames, 0, self::MAX_GROUPS) === $this->groupNames
            && mb_substr($subject, 0, self::MAX_SUBJECT) === $this->subject;
    }

    /**
     * Plans this class as the one that carries on from another: its topic and activity, and its line when
     * something was left over — «seguimos igual». How it goes is not told yet.
     *
     * @param LessonPlan $previous the class it carries on from
     */
    public function continueFrom(LessonPlan $previous): static
    {
        return $this->plan(
            $previous->getTopic(),
            $previous->getActivity(),
            null,
            true === $previous->getOutcome()?->leavesSomethingPending() ? $previous->getNote() : null,
        );
    }

    /**
     * Makes this class hold exactly what another one held — its entries and its line. What moving a planned
     * class one class later amounts to, done on the content so the one-plan-per-class index never sees two
     * plans on the same class mid-way.
     *
     * @param LessonPlan $other the class whose content this one takes
     */
    public function takeContentOf(LessonPlan $other): static
    {
        [$first, $second] = $other->getTopics() + [null, null];

        return $this->planTwo(
            $first?->getTopic(),
            $first?->getActivity(),
            $first?->getOutcome(),
            $second?->getTopic(),
            $second?->getActivity(),
            $second?->getOutcome(),
            $other->getNote(),
        );
    }

    /**
     * Applies the new topic entries in place: mutates the ones already there position by position, adds
     * what is missing and drops what is left over. Never a clear()-then-add() on the collection — that
     * would schedule the WHOLE join table for deletion and lose every add() in the same flush.
     *
     * @param list<array{0: ?Topic, 1: ?LessonActivity, 2: ?LessonOutcome}> $tuples the entries, in order
     */
    private function replaceTopics(array $tuples): void
    {
        $existing = array_values($this->topics->toArray());
        foreach ($tuples as $position => [$topic, $activity, $outcome]) {
            if (isset($existing[$position])) {
                $existing[$position]->update($topic, $activity, $outcome);
            } else {
                $this->topics->add(new LessonPlanTopic($this, $topic, $activity, $outcome, $position));
            }
        }
        for ($i = \count($tuples), $max = \count($existing); $i < $max; ++$i) {
            $this->topics->removeElement($existing[$i]);
        }
    }

    /**
     * Whether nothing at all was planned: an empty plan is not worth keeping.
     */
    public function isEmpty(): bool
    {
        return $this->topics->isEmpty() && null === $this->note;
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

    /**
     * @return list<LessonPlanTopic> the topics worked on, in order — one entry for the ordinary class,
     *                                two for one that closed a topic and opened another
     */
    public function getTopics(): array
    {
        return array_values($this->topics->toArray());
    }

    /**
     * The topic that best represents the class right now: the one it opened, or its only one — what a
     * summary line (the calendar day, "seguimos igual") shows when it has room for just one.
     */
    public function getTopic(): ?Topic
    {
        return $this->lastTopic()?->getTopic();
    }

    public function getActivity(): ?LessonActivity
    {
        return $this->lastTopic()?->getActivity();
    }

    public function getOutcome(): ?LessonOutcome
    {
        return $this->lastTopic()?->getOutcome();
    }

    private function lastTopic(): ?LessonPlanTopic
    {
        $topics = $this->getTopics();

        return [] !== $topics ? $topics[\count($topics) - 1] : null;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }
}
