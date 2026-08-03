<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The band a candidate belongs to when covering a guardia — the order of preference the assignment
 * engine exhausts one band at a time, opening the next only while the groups to cover outnumber the
 * people gathered so far:
 *
 * - {@see self::GUARDIA}: the ordinary rota, straight from the timetable. Always used first.
 * - {@see self::COLLABORATOR}: support duty (the "aula de convivencia"), also in the timetable but
 *   meant as a fallback, not as part of the ordinary rota.
 * - {@see self::SUPPORT}: a colleague the equipo directivo signed up BY HAND for one day and period
 *   because they happen to be free (their Bachillerato or CFGB group has finished lessons). Comes
 *   last of the three: it is a favour, not a duty.
 *
 * Beyond the three bands there is only doubling up — giving a second group to someone already
 * covering one — which the engine reaches for last of all (see {@see \App\Guardia\GuardiaAssigner}).
 */
enum GuardiaDutyBand: string
{
    case GUARDIA = 'guardia';
    case COLLABORATOR = 'collaborator';
    case SUPPORT = 'support';

    /**
     * The bands in the order they are used up: the rota, then collaborators, then hand-added support.
     * The single source of that order — the assignment engine opens bands in this sequence and the parte
     * lists people in it, so neither can drift from the other.
     *
     * @return list<self> the bands, most preferred first
     */
    public static function inPriorityOrder(): array
    {
        return [self::GUARDIA, self::COLLABORATOR, self::SUPPORT];
    }

    /**
     * This band's place in {@see inPriorityOrder()}, for sorting mixed lists of people.
     *
     * @return int the 0-based rank, lowest first
     */
    public function rank(): int
    {
        return (int) array_search($this, self::inPriorityOrder(), true);
    }

    /**
     * Badge text for the candidate lists, or null for the ordinary rota — a "Guardia" badge next to
     * every name in a list of guardia teachers would be noise. Kept here so the parte and the
     * assignment sheet label a band the same way without repeating the condition.
     *
     * @return string|null the badge label, or null when the band needs no badge
     */
    public function label(): ?string
    {
        return match ($this) {
            self::GUARDIA => null,
            self::COLLABORATOR => 'Colaborador',
            self::SUPPORT => 'Apoyo puntual',
        };
    }

    /**
     * Why this band is offered, for the badge's tooltip.
     *
     * @return string|null the explanation, or null when the band needs no badge
     */
    public function hint(): ?string
    {
        return match ($this) {
            self::GUARDIA => null,
            self::COLLABORATOR => 'Colaborador: solo entra si no llega el profesorado de guardia',
            self::SUPPORT => 'Apoyo puntual dado de alta a mano para este día',
        };
    }
}
