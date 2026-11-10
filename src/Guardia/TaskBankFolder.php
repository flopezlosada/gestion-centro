<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\GuardiaTaskBankItem;
use App\Enum\EducationLevel;

/**
 * The outer folder of the bank listing: one level, holding one {@see TaskBankSubjectFolder} per
 * subject. It is how the centre looks for a task ("algo de Matemáticas para 1º de ESO"), so the bank
 * reads as a tree of folders instead of a wall of cards.
 *
 * What comes unfolded is decided here, not in the template: a folder that is the only one of its kind
 * opens (a filtered listing would otherwise be a single closed folder to click), and so does whatever
 * holds the task the screen suggests, which would be hidden otherwise. With a single level on screen
 * every subject in it opens too: otherwise a filter that adds a subject (showing the retired ones, say)
 * folds the one being read, and the screen looks as if it had hidden those tasks.
 */
final readonly class TaskBankFolder
{
    /**
     * @param EducationLevel                        $level    the level
     * @param non-empty-list<TaskBankSubjectFolder> $subjects its subject folders
     * @param bool                                  $open     whether it is painted unfolded
     */
    private function __construct(
        public EducationLevel $level,
        public array $subjects,
        public bool $open,
    ) {
    }

    /**
     * Groups a listing into level folders (in teaching order) and subject folders (in the order they
     * first appear, which is alphabetical when browsing). The tasks keep their order within a folder:
     * when picking, the first one is still the one the random pick could give.
     *
     * @param list<GuardiaTaskBankItem> $items      the listing, already filtered and ordered
     * @param int|null                  $openItemId the task whose folders must come unfolded, if any
     *
     * @return list<self> the folders, empty for an empty listing
     */
    public static function group(array $items, ?int $openItemId = null): array
    {
        /** @var array<string, array<array-key, non-empty-list<GuardiaTaskBankItem>>> $tree */
        $tree = array_reduce($items, static function (array $tree, GuardiaTaskBankItem $item): array {
            $tree[$item->getLevel()->value][$item->getSubject()][] = $item;

            return $tree;
        }, []);

        $levels = array_values(array_filter(
            EducationLevel::inDisplayOrder(),
            static fn (EducationLevel $level): bool => isset($tree[$level->value]),
        ));
        $onlyLevel = 1 === \count($levels);

        return array_map(static function (EducationLevel $level) use ($tree, $onlyLevel, $openItemId): self {
            $bySubject = $tree[$level->value];
            $onlySubject = 1 === \count($bySubject);
            $holding = array_map(static fn (array $tasks): bool => self::holds($tasks, $openItemId), array_values($bySubject));
            $subjects = array_map(
                static fn (string|int $subject, array $tasks, bool $holds): TaskBankSubjectFolder => new TaskBankSubjectFolder(
                    (string) $subject, // una materia que fuese un número llegaría como clave entera
                    $tasks,
                    $onlyLevel || $onlySubject || $holds,
                ),
                array_keys($bySubject),
                array_values($bySubject),
                $holding,
            );

            return new self($level, $subjects, $onlyLevel || \in_array(true, $holding, true));
        }, $levels);
    }

    /**
     * How many tasks the level holds, across its subjects, for the folder's label.
     *
     * @return int the number of tasks
     */
    public function count(): int
    {
        return array_sum(array_map(static fn (TaskBankSubjectFolder $f): int => \count($f->items), $this->subjects));
    }

    /**
     * Whether a set of tasks includes the given one.
     *
     * @param list<GuardiaTaskBankItem> $tasks  the tasks
     * @param int|null                  $taskId the task looked for, null for none
     *
     * @return bool true when it is among them
     */
    private static function holds(array $tasks, ?int $taskId): bool
    {
        return null !== $taskId && [] !== array_filter($tasks, static fn (GuardiaTaskBankItem $t): bool => $t->getId() === $taskId);
    }
}
