<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Enum\Weekday;

/**
 * Weighs what the week needs against what the equipo directivo has pledged, so the shortfall shows up
 * while the quotas are being typed in rather than in October when the rota comes out short.
 *
 * The arithmetic is trivial; the reason it lives in a class of its own is that it is the one number on
 * the screen that decides whether the proposal engine can succeed at all. On the centre's real
 * timetable — six teaching periods, five days, three guardias plus two de apoyo — the week needs 150
 * placements, and with roughly fifty-seven non-exempt teachers that is about 2.6 each. Type "2" for
 * everyone and the rota cannot be filled, however good the engine is.
 *
 * Deliberately pure: it takes plain integers, not entities, so it can be read and tested without a
 * database.
 */
final class GuardiaQuotaBalance
{
    /**
     * Teachers on guardia in each teaching period. The centre's figure ("tiene que haber 3 profes de
     * guardia", 30/07/2026).
     */
    public const GUARDIAS_PER_SLOT = 3;

    /**
     * Teachers on standby in each teaching period — "2 de guardia de apoyo que solo hacen guardias si no
     * hay suficiente profesorado para cubrir esa hora". They occupy a placement even on the quiet weeks:
     * being available is the duty.
     */
    public const SUPPORT_PER_SLOT = 2;

    /**
     * The week's balance sheet.
     *
     * The three states of a teacher are kept apart on purpose, because two of them look identical if you
     * only read the figures. Somebody the equipo directivo has deliberately put at zero is EXEMPT;
     * somebody nobody has typed anything about yet is PENDING; and the difference matters twice over — a
     * screen that greets you calling the whole claustro exempt is lying, and the fair share has to be
     * spread over everyone who could carry a guardia, not only over those already given one, or it reads
     * as zero on the very first visit, when it is the most useful number on the page.
     *
     * @param int                                                        $lectiveSlots teaching periods in the centre's timetable frame
     * @param list<array{lective: int, break: int, configured: bool}> $quotas       one entry per teacher on the timetable
     *
     * @return array{
     *     slots: int, days: int, perSlot: int, needed: int, pledged: int, gap: int, surplus: int,
     *     teachers: int, staffed: int, exempt: int, pending: int, available: int, fairShare: float,
     *     breakPledged: int
     * } the placements needed, what has been pledged and how far apart they are
     */
    public function summarise(int $lectiveSlots, array $quotas): array
    {
        $days = \count(Weekday::schoolWeek());
        $perSlot = self::GUARDIAS_PER_SLOT + self::SUPPORT_PER_SLOT;
        $needed = $lectiveSlots * $days * $perSlot;

        $pledged = 0;
        $breakPledged = 0;
        $exempt = 0;
        $pending = 0;
        $staffed = 0;
        foreach ($quotas as $quota) {
            $pledged += $quota['lective'];
            $breakPledged += $quota['break'];

            if (!$quota['configured']) {
                ++$pending;
                continue;
            }
            // Exempt means nothing at all, in either pool: somebody down to a single recreo is still
            // carrying part of the rota, and counting them out would overstate how few people are left.
            if (0 === $quota['lective'] && 0 === $quota['break']) {
                ++$exempt;
                continue;
            }
            ++$staffed;
        }

        $teachers = \count($quotas);
        $available = $teachers - $exempt;

        return [
            'slots' => $lectiveSlots,
            'days' => $days,
            'perSlot' => $perSlot,
            'needed' => $needed,
            'pledged' => $pledged,
            'gap' => max(0, $needed - $pledged),
            'surplus' => max(0, $pledged - $needed),
            'teachers' => $teachers,
            'staffed' => $staffed,
            'exempt' => $exempt,
            'pending' => $pending,
            'available' => $available,
            'fairShare' => $available > 0 ? round($needed / $available, 1) : 0.0,
            'breakPledged' => $breakPledged,
        ];
    }
}
