<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where the acta of a staff meeting is in its life: the one fact the detail screen is built around. It
 * decides the pill next to the "Acta" heading, which step of the cycle is lit and — the reason it exists —
 * the ONE primary action on screen: generate while there is no PDF or it went stale, publish a draft that
 * is up to date, approve a published acta when the body requires it.
 *
 * Derived, never stored ({@see \App\Entity\Meeting::minutesStage()}): every case is a reading of fields the
 * meeting already keeps, so it cannot disagree with them.
 */
enum MinutesStage: string
{
    /** The meeting has not started: nothing to record yet. */
    case NOT_STARTED = 'antes';
    /** Held, but no PDF has been generated or uploaded. */
    case NO_PDF = 'sin_pdf';
    /** There is a PDF, but the text or the roll changed after it was produced. Wins over published. */
    case OUTDATED = 'desactualizada';
    /** There is an up-to-date PDF that only whoever keeps the minutes can see. */
    case DRAFT = 'borrador';
    /** Sent out and, if the body approves its actas, not approved yet. */
    case PUBLISHED = 'publicada';
    /** Published and approved by the body. */
    case APPROVED = 'aprobada';

    /**
     * The step of the cycle (Asistencia · Redacción · PDF · Publicación · Aprobación) this stage is at,
     * counting from 1. A value past the last step means the cycle is finished: a published acta whose body
     * approves nothing has 4 steps, so its 5 already reads as "all done".
     *
     * @return int the 1-based current step
     */
    public function step(): int
    {
        return match ($this) {
            self::NOT_STARTED => 1,
            self::NO_PDF, self::OUTDATED => 3,
            self::DRAFT => 4,
            self::PUBLISHED => 5,
            self::APPROVED => 6,
        };
    }

    /**
     * The pill next to the heading, for whoever writes the acta. Long on desktop, short on a phone.
     *
     * @param bool $approvalRequired whether the body approves its actas (changes how "published" reads)
     *
     * @return array{0: string, 1: string} the long and the short label
     */
    public function labels(bool $approvalRequired): array
    {
        return match ($this) {
            self::NOT_STARTED => ['Aún no ha empezado', 'Aún no'],
            self::NO_PDF => ['Sin PDF', 'Sin PDF'],
            self::OUTDATED => ['PDF desactualizado', 'Desactualizada'],
            self::DRAFT => ['Borrador · solo lo ves tú', 'Borrador'],
            self::PUBLISHED => $approvalRequired ? ['Publicada · pendiente de aprobar', 'Publicada'] : ['Publicada', 'Publicada'],
            self::APPROVED => ['Aprobada', 'Aprobada'],
        };
    }
}
