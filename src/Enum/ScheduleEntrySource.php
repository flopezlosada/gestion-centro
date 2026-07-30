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
 * - {@see self::ENGINE}: placed by the rota proposal engine and approved by the equipo directivo. An
 *   import leaves these alone too, but the engine may replace its own on the next run — which is the
 *   whole point of telling them apart from MANUAL: re-proposing must never undo a human's retouch, so
 *   hand-edited cells are treated as fixed and only the engine's own are redrawn.
 */
enum ScheduleEntrySource: string
{
    case PENALARA = 'penalara';
    case MANUAL = 'manual';
    case ENGINE = 'engine';

    /**
     * The sources an import must not overwrite: everything a person or the engine put there. Kept as a
     * list rather than "not PENALARA" so adding a fourth writer forces a decision here instead of
     * silently inheriting protection.
     *
     * @return list<self> the protected sources
     */
    public static function protectedFromImport(): array
    {
        return [self::MANUAL, self::ENGINE];
    }
}
