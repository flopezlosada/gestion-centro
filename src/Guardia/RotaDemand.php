<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Enum\ScheduleActivityKind;

/**
 * How many people the centre wants on duty in every teaching period, and in what capacity.
 *
 * The centre's words (30/07/2026): "tiene que haber 3 profes de guardia y 2 de guardia de apoyo que solo
 * hacen guardias si no hay suficiente profesorado para cubrir esa hora". The second group is what the
 * timetable already calls a {@see ScheduleActivityKind::COLLABORATOR} — the band the daily parte only
 * opens once the ordinary guardias have run out ({@see \App\Enum\GuardiaDutyBand::COLLABORATOR}) — so
 * this proposes into the vocabulary that exists rather than inventing a parallel one.
 *
 * The two figures live here, in one place, because both the proposal engine and the quota balance need
 * them and a screen that says the week needs 150 placements while the engine places 120 is worse than
 * either being wrong on its own.
 *
 * They are constants, not configuration. Making them editable is a small change the day a centre wants
 * four and two; inventing a settings screen before anybody has asked is not.
 */
final class RotaDemand
{
    /** Teachers on ordinary guardia in each teaching period. */
    public const GUARDIAS_PER_SLOT = 3;

    /** Teachers on standby in each teaching period; being available is the duty. */
    public const SUPPORT_PER_SLOT = 2;

    /**
     * Everyone a single teaching period needs, ordinary guardias first.
     *
     * Order matters: the engine fills a period in this sequence, so when candidates run out it is the
     * standby places that go unfilled, never the guardias the period actually depends on.
     *
     * @return list<ScheduleActivityKind> one entry per place to fill, in filling order
     */
    public static function shifts(): array
    {
        return array_merge(
            array_fill(0, self::GUARDIAS_PER_SLOT, ScheduleActivityKind::GUARDIA),
            array_fill(0, self::SUPPORT_PER_SLOT, ScheduleActivityKind::COLLABORATOR),
        );
    }

    /**
     * How many people one teaching period needs in total.
     *
     * @return int the places per period
     */
    public static function perSlot(): int
    {
        return self::GUARDIAS_PER_SLOT + self::SUPPORT_PER_SLOT;
    }
}
