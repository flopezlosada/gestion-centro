<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Department;
use App\Entity\Task;
use App\Entity\TaskTemplate;
use App\Entity\User;
use App\Repository\DepartmentRepository;
use App\Repository\TaskRepository;
use App\Repository\TaskTemplateRepository;
use App\Support\TaskGenerationKey;
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
        private readonly DepartmentRepository $departments,
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
        // Read ONCE for the whole run, not once per template: the list is the same for all of them,
        // and the repository does not cache, so asking inside the loop was the same query five times.
        $allDepartments = $this->departments->findActiveDepartments();

        $created = 0;
        $skippedExisting = 0;
        $skippedWithoutRule = 0;

        foreach ($this->templates->findActive() as $template) {
            $rule = $template->getDueDateRule();
            if (null === $rule) {
                ++$skippedWithoutRule;
                continue;
            }

            // A template for a per-department function is not ONE task: it is one per department.
            // "Memoria del departamento" is twenty-one different memorias, each delivered, commented
            // and validated on its own — the same reason the task form creates one task per person
            // instead of one shared row. A single task here would be held by every jefe de
            // departamento in the centre at once, and the first one to deliver would speak for all.
            $departments = $this->scopesFor($template, $allDepartments);

            foreach ($rule->resolve($year) as $date) {
                $dueDate = $this->calendar->onOrBeforeLectiveDay($date);

                foreach ($departments as $department) {
                    $key = TaskGenerationKey::for($template->getId(), $dueDate, $department?->getId());
                    if (isset($existing[$key])) {
                        ++$skippedExisting;
                        continue;
                    }

                    $task = Task::fromTemplate($template, $schoolYear, $dueDate, $department);
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
        }

        $this->em->flush();

        return new GenerationResult($created, $skippedExisting, $skippedWithoutRule);
    }

    /**
     * The departments a template expands into: every active one when its function is per-department,
     * or a single null (one centre-wide task) otherwise.
     *
     * Every department, INCLUDING the ones with nobody holding the post. Skipping those would make a
     * department quietly disappear from the course plan the year its jefatura falls vacant — the one
     * year somebody most needs to notice. Generated with no assignee, such a task resolves to nobody
     * and reads as "Sin asignar" in the list, which is a hole dirección can see and fill; the moment
     * the post is filled, the task follows the new holder on its own, with nothing to re-run.
     *
     * A template with no responsible role at all is centre-wide too: there is no function to spread.
     *
     * @param TaskTemplate     $template    the template being generated
     * @param list<Department> $departments the centre's active departments, read once for the whole run
     *
     * @return list<Department|null> the scopes to generate one task for each
     */
    private function scopesFor(TaskTemplate $template, array $departments): array
    {
        if (true !== $template->getResponsibleRole()?->isPerDepartment()) {
            return [null];
        }

        // A centre with no departments configured yet still gets its task, rather than silently
        // generating nothing at all.
        return [] !== $departments ? $departments : [null];
    }
}
