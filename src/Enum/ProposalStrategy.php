<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The criterion an alternative was built with. This is what makes "varias opciones" mean something: the
 * three strategies are not random shuffles, they optimise for different things a person actually cares
 * about, and each option says which one it followed.
 *
 * With forty rooms, two of them will often produce the same plan. The interface says so ("B y C son
 * equivalentes") instead of dressing up three identical cards.
 */
enum ProposalStrategy: string
{
    /** Send each group to the nearest free room: same building and floor before anything else. */
    case NEAREST = 'nearest';

    /** Keep each group in the SAME room for every day of the plan, even if it means moving it further. */
    case STABLE_ROOM = 'stable_room';

    /** Keep labs, workshops and the computer room out of it, even if the alternative is further away. */
    case PRESERVE_SPECIALISED = 'preserve_specialised';

    /** Not generated: built or reshaped by a person. */
    case MANUAL = 'manual';

    /**
     * Human-facing name (Spanish).
     *
     * @return string the strategy label
     */
    public function label(): string
    {
        return match ($this) {
            self::NEAREST => 'Lo más cerca posible',
            self::STABLE_ROOM => 'Misma aula todos los días',
            self::PRESERVE_SPECIALISED => 'Respetar laboratorios y talleres',
            self::MANUAL => 'Hecha a mano',
        };
    }

    /**
     * What the criterion buys and what it costs, for the person choosing between options.
     *
     * @return string the trade-off, in one line
     */
    public function help(): string
    {
        return match ($this) {
            self::NEAREST => 'Cada grupo va al aula libre más cercana a la suya. Lo más cómodo para moverse, pero un grupo puede acabar en un aula distinta cada día.',
            self::STABLE_ROOM => 'Cada grupo tiene una sola aula durante todo el plan: menos lío para el alumnado, aunque a veces quede más lejos.',
            self::PRESERVE_SPECIALISED => 'Evita ocupar laboratorios, talleres e informática con clases ordinarias, aunque haya que mover a los grupos más lejos.',
            self::MANUAL => 'Alguien la ha construido o retocado a mano.',
        };
    }

    /**
     * The strategies the engine generates, in the order the options are presented.
     *
     * @return list<ProposalStrategy> the generated strategies (never {@see MANUAL})
     */
    public static function generated(): array
    {
        return [self::NEAREST, self::STABLE_ROOM, self::PRESERVE_SPECIALISED];
    }
}
