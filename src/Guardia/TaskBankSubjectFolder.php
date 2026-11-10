<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\GuardiaTaskBankItem;

/**
 * The inner folder of the bank listing: the tasks of one subject within one level, in the order the
 * listing gave them (least used first when picking for a guardia, newest first when browsing).
 * Built by {@see TaskBankFolder::group()}.
 */
final readonly class TaskBankSubjectFolder
{
    /**
     * @param string                              $subject the subject, as the timetable names it
     * @param non-empty-list<GuardiaTaskBankItem> $items   the tasks in it
     * @param bool                                $open    whether it is painted unfolded
     */
    public function __construct(
        public string $subject,
        public array $items,
        public bool $open,
    ) {
    }
}
