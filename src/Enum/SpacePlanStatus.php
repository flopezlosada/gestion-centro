<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a {@see \App\Entity\SpacePlan} is in its life: from being drafted to being the truth about a
 * day.
 *
 * Four states, not six. "Approved" and "published" are not two states but one state and two actions
 * with a timestamp ({@code notifiedAt}, {@code publishedAt}) — the centre is never going to approve a
 * room change and keep it to themselves, so making that a state would only double the screens.
 *
 * The rule that matters: ONLY {@see APPROVED} alters the effective timetable. A draft changes nothing
 * anybody else can see.
 */
enum SpacePlanStatus: string
{
    /** Being defined: dates, periods and what the event occupies. Nothing generated yet. */
    case DRAFT = 'draft';

    /** Alternatives generated; the equipo directivo is comparing and editing them. */
    case PROPOSED = 'proposed';

    /** Decided. From here on it overrides the ordinary timetable for its dates. */
    case APPROVED = 'approved';

    /** Called off. Kept rather than deleted: what was announced and then cancelled is worth a trail. */
    case CANCELLED = 'cancelled';

    /**
     * Human-facing name (Spanish).
     *
     * @return string the status label
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Borrador',
            self::PROPOSED => 'Propuestas generadas',
            self::APPROVED => 'Aprobado',
            self::CANCELLED => 'Anulado',
        };
    }

    /**
     * The CSS badge variant that carries this status in the interface.
     *
     * @return string the badge modifier class
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::DRAFT => 'badge--muted',
            self::PROPOSED => 'badge--warning',
            self::APPROVED => 'badge--ok',
            self::CANCELLED => 'badge--danger',
        };
    }

    /**
     * Whether a plan in this state is what the centre actually does — the one condition under which it
     * overrides the ordinary timetable, is announced and is published.
     *
     * @return bool true only for {@see APPROVED}
     */
    public function isInForce(): bool
    {
        return self::APPROVED === $this;
    }

    /**
     * Whether the plan can still be edited (its enunciado, its alternatives, its lines).
     *
     * @return bool true while it is not yet decided
     */
    public function isEditable(): bool
    {
        return self::DRAFT === $this || self::PROPOSED === $this;
    }
}
