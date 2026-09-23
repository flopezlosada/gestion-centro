<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LessonActivity;
use App\Enum\LessonOutcome;
use Doctrine\ORM\Mapping as ORM;

/**
 * One topic worked on within a class plan: what it was, what the class did with it, and how it went.
 *
 * A class ordinarily works on ONE topic ({@see LessonPlan::plan()}), but a class that finishes one and
 * starts the next is common enough to count for something other than the topic it happened to end on
 * ({@see LessonPlan::planTwo()}): it is half a class for each. Position orders the two — 0 is the topic
 * the class closed (or its only topic), 1 the one it opened.
 */
#[ORM\Entity]
#[ORM\Table(name: 'lesson_plan_topic')]
#[ORM\UniqueConstraint(name: 'uniq_lesson_plan_topic_position', columns: ['lesson_plan_id', 'position'])]
class LessonPlanTopic
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LessonPlan::class, inversedBy: 'topics')]
    #[ORM\JoinColumn(name: 'lesson_plan_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private LessonPlan $lessonPlan;

    #[ORM\ManyToOne(targetEntity: Topic::class)]
    #[ORM\JoinColumn(name: 'topic_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Topic $topic;

    #[ORM\Column(length: 16, nullable: true, enumType: LessonActivity::class)]
    private ?LessonActivity $activity;

    #[ORM\Column(length: 16, nullable: true, enumType: LessonOutcome::class)]
    private ?LessonOutcome $outcome;

    #[ORM\Column(type: 'smallint')]
    private int $position;

    public function __construct(LessonPlan $lessonPlan, ?Topic $topic, ?LessonActivity $activity, ?LessonOutcome $outcome, int $position)
    {
        $this->lessonPlan = $lessonPlan;
        $this->topic = $topic;
        $this->activity = $activity;
        $this->outcome = $outcome;
        $this->position = $position;
    }

    /**
     * Overwrites what was said about this entry, in place — so editing an already-saved plan mutates its
     * rows instead of deleting and reinserting them at the same position within one flush.
     */
    public function update(?Topic $topic, ?LessonActivity $activity, ?LessonOutcome $outcome): void
    {
        $this->topic = $topic;
        $this->activity = $activity;
        $this->outcome = $outcome;
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

    public function getPosition(): int
    {
        return $this->position;
    }
}
