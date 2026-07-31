<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Who put a place on the break rota.
 *
 * Two writers feed the same table and re-proposing must not undo the other one's work:
 *
 * - {@see self::MANUAL}: somebody added it from the rota screen. The centre organises the patios
 *   dirigidos by hand and by day, so these are decisions, not suggestions — the engine treats them as
 *   fixed and works around them.
 * - {@see self::ENGINE}: the proposal engine placed it and the equipo directivo approved it. A new
 *   proposal replaces exactly these, which is what makes re-running it safe.
 *
 * Same shape as {@see ScheduleEntrySource} for the teaching rota, kept apart because the break rota has
 * no third writer: Peñalara does not export recreos at all.
 */
enum BreakDutySource: string
{
    case MANUAL = 'manual';
    case ENGINE = 'engine';
}
