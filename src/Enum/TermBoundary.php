<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which edge of a term a "per term" deadline lands on: its start or its end. Applied to all three
 * terms by a {@see \App\DueDate\PerTerm} rule.
 */
enum TermBoundary: string
{
    case START = 'start';
    case END = 'end';

    /**
     * Human-facing label (Spanish).
     *
     * @return string the boundary label
     */
    public function label(): string
    {
        return match ($this) {
            self::START => 'Inicio de cada trimestre',
            self::END => 'Fin de cada trimestre',
        };
    }
}
