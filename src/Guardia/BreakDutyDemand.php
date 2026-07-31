<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\BreakZone;
use App\Enum\BreakPeriod;
use App\Enum\Weekday;
use App\Repository\BreakZoneDemandRepository;

/**
 * How many people each cell of the break rota needs: the zone's own figure, unless somebody has singled
 * that cell out.
 *
 * One place answers the question so the grid, the shortfall count and the proposal engine cannot drift
 * apart — a screen saying a zone is short while the engine thinks it is full would be worse than either
 * being wrong alone.
 *
 * The exceptions are read once and cached for the request. Everything that asks this asks it fifty
 * times in a row (five zones × five weekdays × two recreos), and the table holds a handful of rows.
 */
final class BreakDutyDemand
{
    /** @var array<string, int>|null the exceptions by cell key, read on first use */
    private ?array $exceptions = null;

    public function __construct(private readonly BreakZoneDemandRepository $demands)
    {
    }

    /**
     * How many people this zone needs at this recreo of this weekday.
     *
     * @param BreakZone   $zone    the zone
     * @param Weekday     $weekday the weekday
     * @param BreakPeriod $period  which recreo
     *
     * @return int the people needed; zero means the zone is not watched then
     */
    public function required(BreakZone $zone, Weekday $weekday, BreakPeriod $period): int
    {
        $this->exceptions ??= $this->demands->allByCell();
        $key = $zone->getId().':'.$weekday->value.':'.$period->value;

        return $this->exceptions[$key] ?? $zone->getRequiredTeachers();
    }

    /**
     * Whether this cell's figure was set by hand rather than inherited from the zone — what lets the
     * editing screen show which cells somebody has deliberately touched.
     *
     * @param BreakZone   $zone    the zone
     * @param Weekday     $weekday the weekday
     * @param BreakPeriod $period  which recreo
     *
     * @return bool true when an exception exists for the cell
     */
    public function isOverridden(BreakZone $zone, Weekday $weekday, BreakPeriod $period): bool
    {
        $this->exceptions ??= $this->demands->allByCell();

        return isset($this->exceptions[$zone->getId().':'.$weekday->value.':'.$period->value]);
    }

    /**
     * Total places the week needs across every zone and both recreos, and how they split between the
     * long recreo and the short one.
     *
     * The split is the figure that decides whether the rota can even balance: a guardia is one long plus
     * one short, so a week with more of one than the other leaves people holding halves no matter how
     * well anything is distributed.
     *
     * @param list<BreakZone> $zones the zones in use
     *
     * @return array{long: int, short: int, total: int, unpairable: int} the weekly demand
     */
    public function weeklyTotals(array $zones): array
    {
        $long = 0;
        $short = 0;
        foreach (Weekday::schoolWeek() as $weekday) {
            foreach ($zones as $zone) {
                $long += $this->required($zone, $weekday, BreakPeriod::FIRST);
                $short += $this->required($zone, $weekday, BreakPeriod::SECOND);
            }
        }

        return [
            'long' => $long,
            'short' => $short,
            'total' => $long + $short,
            'unpairable' => abs($long - $short),
        ];
    }
}
