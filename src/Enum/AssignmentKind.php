<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What one line of a plan says. Two kinds, because there are only two things a plan can state about a
 * space at a given moment.
 */
enum AssignmentKind: string
{
    /** A lesson that was somewhere and now happens somewhere else. Carries the room it came from. */
    case RELOCATION = 'relocation';

    /**
     * Something occupies a space: the external exam in the assembly hall, a workshop of the cultural
     * days. With a teacher and groups when the centre assigns them, without when it is an outside body.
     */
    case ACTIVITY = 'activity';

    /**
     * Human-facing name (Spanish).
     *
     * @return string the kind label
     */
    public function label(): string
    {
        return match ($this) {
            self::RELOCATION => 'Cambio de aula',
            self::ACTIVITY => 'Actividad',
        };
    }
}
