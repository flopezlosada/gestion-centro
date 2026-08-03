<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The identity of one task produced by the yearly generation: which template, for which deadline, for
 * which department. It is what makes a re-run idempotent — {@see \App\Service\TaskGenerator} builds the
 * key of every task it is about to create and {@see \App\Repository\TaskRepository::generatedKeysFor()}
 * builds the key of every task already there, so both have to spell it the same way.
 *
 * It lives here, with no dependencies, precisely so that neither of those two has to import the other.
 * The generator already depends on the repository; having the repository reach back into the service
 * for a static helper would have closed the loop the wrong way round.
 *
 * The DEPARTMENT is part of the identity, and that is the whole point: one template and one date
 * legitimately produce twenty-one tasks, one per department. Keyed by template and date alone, a
 * second run would find the first department's task and skip the other twenty as already generated.
 */
final class TaskGenerationKey
{
    /**
     * The key of one generated instance.
     *
     * @param int|null           $templateId   the template's id
     * @param \DateTimeImmutable $dueDate      the resolved deadline
     * @param int|null           $departmentId the department's id, or null for a centre-wide task
     *
     * @return string the lookup key
     */
    public static function for(?int $templateId, \DateTimeImmutable $dueDate, ?int $departmentId): string
    {
        return $templateId.'|'.$dueDate->format('Y-m-d').'|'.$departmentId;
    }
}
