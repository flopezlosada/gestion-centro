<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a planned class went, marked afterwards with one tap. Unmarked (null) means "planned, not told
 * yet". What was left half done or not reached is what the next class with the same group picks up.
 */
enum LessonOutcome: string
{
    case DONE = 'hecho';
    case PARTIAL = 'a_medias';
    case NOT_REACHED = 'no_dio_tiempo';

    /**
     * Human-facing label (Spanish).
     *
     * @return string the label
     */
    public function label(): string
    {
        return match ($this) {
            self::DONE => 'Hecho',
            self::PARTIAL => 'A medias',
            self::NOT_REACHED => 'No dio tiempo',
        };
    }

    /**
     * Whether something was left over for the next class.
     */
    public function leavesSomethingPending(): bool
    {
        return self::DONE !== $this;
    }
}
