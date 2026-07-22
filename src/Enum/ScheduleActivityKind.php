<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The nature of a slot in a teacher's weekly timetable, as imported from Peñalara GHC.
 *
 * A slot is either real teaching ({@see LECTIVE}, tied to a group/room/subject) or a non-teaching
 * duty: a {@see GUARDIA} (the teacher is on call to cover an absent colleague) or a
 * {@see COLLABORATOR} slot (support duty, e.g. the "aula de convivencia", used to cover only when
 * the ordinary guardias are not enough). The distinction is what lets the assignment engine know
 * who is available to cover each hour.
 */
enum ScheduleActivityKind: string
{
    case LECTIVE = 'lective';
    case GUARDIA = 'guardia';
    case COLLABORATOR = 'collaborator';

    /**
     * Human-facing label (Spanish).
     *
     * @return string the kind label
     */
    public function label(): string
    {
        return match ($this) {
            self::LECTIVE => 'Lectiva',
            self::GUARDIA => 'Guardia',
            self::COLLABORATOR => 'Colaborador',
        };
    }
}
