<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a TIC incident is: reported, being looked at, or done with.
 *
 * The centre did not ask for states — only for a register. They are here anyway, and it is a deliberate
 * addition rather than an oversight of YAGNI: a register with no way of saying "ya está" only grows, and
 * within a term nobody can tell what is still broken from what was fixed in October. Three states and no
 * more, so it stays something a person updates in one click.
 */
enum IncidentStatus: string
{
    /** Registrada, nadie la ha cogido todavía. */
    case OPEN = 'open';

    /** Alguien está en ello. */
    case IN_PROGRESS = 'in_progress';

    /** Arreglada (o descartada, con su explicación). */
    case RESOLVED = 'resolved';

    /**
     * The label shown on screen.
     *
     * @return string the Spanish label
     */
    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Sin atender',
            self::IN_PROGRESS => 'En curso',
            self::RESOLVED => 'Resuelta',
        };
    }

    /**
     * Whether the incident is still something to deal with.
     *
     * @return bool true unless resolved
     */
    public function isOpen(): bool
    {
        return self::RESOLVED !== $this;
    }

    /**
     * The tone class the screens paint it with.
     *
     * @return string a badge modifier
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::OPEN => 'badge--warning',
            self::IN_PROGRESS => 'badge--review',
            self::RESOLVED => 'badge--success',
        };
    }
}
