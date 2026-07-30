<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a period of the centre's marco horario is for: an ordinary teaching period ({@see LECTIVE}) or a
 * break ({@see BREAK_TIME}) — the two recreos of the school day, which carry no lesson but do carry
 * their own duty rota (see {@see \App\Entity\BreakDutyAssignment}).
 *
 * Peñalara marks each tramo with a {@code <Tipo>} we map here: "recreo" is a break, anything else is
 * treated as teaching time. Mapping the unknown to LECTIVE is deliberate — a period we cannot classify
 * must not silently become a recreo and start demanding a zone rota.
 */
enum TimeSlotKind: string
{
    case LECTIVE = 'lective';
    case BREAK_TIME = 'break';

    /**
     * Builds the kind from the Peñalara {@code <Tipo>} of a tramo.
     *
     * @param string $penalaraType the raw type ("lectivo", "recreo"…), any case
     *
     * @return self BREAK_TIME for a recreo, LECTIVE otherwise
     */
    public static function fromPenalaraType(string $penalaraType): self
    {
        return 'recreo' === mb_strtolower(trim($penalaraType)) ? self::BREAK_TIME : self::LECTIVE;
    }

    /**
     * Human-facing label (Spanish).
     *
     * @return string the kind label
     */
    public function label(): string
    {
        return match ($this) {
            self::LECTIVE => 'Lectivo',
            self::BREAK_TIME => 'Recreo',
        };
    }
}
