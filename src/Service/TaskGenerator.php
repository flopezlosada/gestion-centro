<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Repository\TaskTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Generates a course's tasks from the catalogue: for each active {@see \App\Entity\TaskTemplate}
 * that carries a deadline rule, it resolves the rule against the {@see AcademicYear} into one or more
 * dates, snaps each onto a teaching day, and creates a {@see Task} for it (assigned by the template's
 * responsible role, linked back to the template).
 *
 * Idempotent: a task already generated for a given template and date is skipped, so the action can be
 * re-run safely after adding templates or fixing dates. Templates without a rule are left for the
 * deadline to be set by hand.
 */
final class TaskGenerator
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly TaskTemplateRepository $templates,
        private readonly SchoolCalendar $calendar,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Generates the tasks for the given course and persists them.
     *
     * @param AcademicYear $year      the course to generate for (supplies the term structure)
     * @param User|null    $createdBy the user running the generation, recorded on each task
     *
     * @return GenerationResult what was created and skipped
     */
    public function generate(AcademicYear $year, ?User $createdBy): GenerationResult
    {
        $schoolYear = $year->getSchoolYear();
        $existing = $this->tasks->generatedKeysFor($schoolYear);

        $created = 0;
        $skippedExisting = 0;
        $skippedWithoutRule = 0;

        foreach ($this->templates->findActive() as $template) {
            $rule = $template->getDueDateRule();
            if (null === $rule) {
                ++$skippedWithoutRule;
                continue;
            }

            foreach ($rule->resolve($year) as $date) {
                $dueDate = $this->calendar->onOrBeforeLectiveDay($date);
                $key = $template->getId().'|'.$dueDate->format('Y-m-d');
                if (isset($existing[$key])) {
                    ++$skippedExisting;
                    continue;
                }

                $task = Task::fromTemplate($template, $schoolYear, $dueDate);
                if (null !== $createdBy) {
                    $task->setCreatedBy($createdBy);
                }
                $this->em->persist($task);

                // Guard against two of this template's dates snapping onto the same teaching day
                // within a single run.
                $existing[$key] = true;
                ++$created;
            }
        }

        $this->em->flush();

        return new GenerationResult($created, $skippedExisting, $skippedWithoutRule);
    }
}
