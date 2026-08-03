<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\GuardiaCover;
use App\Entity\User;

/**
 * Una hora de la semana de exámenes, tal y como la tiene que leer quien coordina: quién está acompañando un
 * examen (y qué guardias tiene puestas, que son las que hay que pasar a otra persona), quién queda libre
 * porque su grupo está examinándose, y cuántas líneas del parte siguen sin nadie.
 *
 * Las dos listas son las dos mitades de la misma frase del centro —"las guardias del profesorado
 * acompañante las cubren los compañeros del nivel"— y por eso viajan juntas: mirar solo una de ellas
 * llevaría a quitarle la guardia a alguien sin ver quién puede cogerla, o al contrario.
 */
final readonly class ExamPeriodSlot
{
    /**
     * @param int                                                              $slotIndex   the period index within the day
     * @param string|null                                                      $timeLabel   "08:25–09:20", or null without an imported timetable
     * @param list<array{teacher: User, what: string, room: ?string, covers: list<GuardiaCover>}> $supervising quien acompaña un examen y las guardias que tiene puestas a esa hora
     * @param list<array{teacher: User, groups: list<string>, alreadySupport: bool}>          $freed       a quién dejan libre los exámenes, con los grupos que se examinan
     * @param int                                                              $uncovered   líneas del parte de esa hora sin nadie asignado
     */
    public function __construct(
        public int $slotIndex,
        public ?string $timeLabel,
        public array $supervising,
        public array $freed,
        public int $uncovered,
    ) {
    }

    /** Cómo nombrar la hora cuando no hay horario importado y no se sabe a qué hora empieza. */
    public function label(): string
    {
        return $this->timeLabel ?? sprintf('%d.ª hora', $this->slotIndex + 1);
    }

    /**
     * Cuántas guardias hay que pasar a otra persona a esta hora: las que tiene puestas quien acompaña un
     * examen. Es la cifra que justifica la pantalla.
     *
     * @return int the guardias to hand over
     */
    public function guardiasToHandOver(): int
    {
        return array_sum(array_map(static fn (array $row): int => \count($row['covers']), $this->supervising));
    }

    /**
     * A quién se puede dar de alta como apoyo todavía (los que aún no lo están).
     *
     * @return list<array{teacher: User, groups: list<string>, alreadySupport: bool}> the pending ones
     */
    public function proposable(): array
    {
        return array_values(array_filter($this->freed, static fn (array $row): bool => !$row['alreadySupport']));
    }

    /**
     * Si esta hora tiene algo que decir. Una hora del plan en la que nadie acompaña, nadie queda libre y no
     * falta nadie por cubrir es ruido en la pantalla.
     *
     * @return bool true when the period is worth showing
     */
    public function isRelevant(): bool
    {
        return [] !== $this->supervising || [] !== $this->freed || $this->uncovered > 0;
    }
}
