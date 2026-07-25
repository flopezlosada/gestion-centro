<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a {@see \App\Entity\ScheduleEntry} came from. Two writers feed the same table and a re-import
 * must not undo the other one's work, so each cell records who put it there:
 *
 * - {@see self::PENALARA}: loaded from a GHC export. An import replaces exactly these — that is what
 *   makes re-running it idempotent.
 * - {@see self::MANUAL}: marked by hand in the "horario de guardias" editor, the fallback for when the
 *   export carries a teacher's lessons but not their guardias. An import leaves these alone (and skips
 *   any cell that would land on top of one, so nobody ends up counted twice in the guardia pool).
 */
enum ScheduleEntrySource: string
{
    case PENALARA = 'penalara';
    case MANUAL = 'manual';
}
