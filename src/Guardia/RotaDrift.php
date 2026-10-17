<?php

declare(strict_types=1);

namespace App\Guardia;

/**
 * How far the published rota is from the one the engine would publish today: the guardias a new
 * publication would add and the ones it would take away.
 *
 * A move counts once on each side — somebody leaving Monday's third period for Tuesday's is one guardia
 * out and one in — because that is what the equipo directivo would see change on the grid.
 */
final class RotaDrift
{
    /**
     * @param int $added   guardias the engine would place that are not published
     * @param int $removed published guardias the engine would no longer place
     */
    public function __construct(
        public readonly int $added,
        public readonly int $removed,
    ) {
    }

    /**
     * Whether the published rota is still exactly what the engine would publish.
     *
     * @return bool true when nothing would change
     */
    public function isEmpty(): bool
    {
        return 0 === $this->added && 0 === $this->removed;
    }
}
