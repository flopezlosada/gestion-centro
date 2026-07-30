<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What kind of alteration a {@see \App\Entity\SpacePlan} is — the centre's own three cases.
 *
 * IMPORTANT: this is a LABEL and a form preset, never a branch in the logic. The whole point of the
 * design is that the three cases are one mechanism: what actually changes between them is
 * {@see SubstitutionScope} (whose ordinary timetable stops applying) and which proposer generates the
 * alternatives. If a {@code match} on this enum ever appears in a service, the abstraction has leaked.
 */
enum SpacePlanKind: string
{
    /** A workshop, an external exam or a talk takes rooms and the groups in them have to go elsewhere. */
    case ROOM_CHANGE = 'room_change';

    /** Exam week: a level sits its exams in rooms that belong to somebody else (the English ones). */
    case EXAM_PERIOD = 'exam_period';

    /** Cultural days, equality day: an alternative timetable of workshops replaces the ordinary one. */
    case SPECIAL_DAY = 'special_day';

    /**
     * Human-facing name (Spanish).
     *
     * @return string the kind label
     */
    public function label(): string
    {
        return match ($this) {
            self::ROOM_CHANGE => 'Cambio de aula',
            self::EXAM_PERIOD => 'Semana de exámenes',
            self::SPECIAL_DAY => 'Jornada con horario alternativo',
        };
    }

    /**
     * One line explaining when to pick it, shown next to the choice.
     *
     * @return string the help text
     */
    public function help(): string
    {
        return match ($this) {
            self::ROOM_CHANGE => 'Un taller, una prueba externa o una charla ocupan aulas y hay que recolocar a los grupos que estaban en ellas.',
            self::EXAM_PERIOD => 'Un nivel hace sus exámenes en aulas de otros (las de Inglés) durante varios días.',
            self::SPECIAL_DAY => 'Jornadas culturales o de igualdad: ese día no hay horario ordinario, sino talleres.',
        };
    }

    /**
     * The substitution scope this kind starts with. Only a default for the form — the person can change
     * it, because only they know whether the groups sitting exams also miss their ordinary lessons.
     *
     * @return SubstitutionScope the scope to preselect
     */
    public function defaultScope(): SubstitutionScope
    {
        return match ($this) {
            self::ROOM_CHANGE => SubstitutionScope::NONE,
            self::EXAM_PERIOD => SubstitutionScope::GROUPS,
            self::SPECIAL_DAY => SubstitutionScope::WHOLE_CENTRE,
        };
    }

    /**
     * The curated order in which kinds are offered.
     *
     * @return list<SpacePlanKind> every kind, in display order
     */
    public static function inDisplayOrder(): array
    {
        return [self::ROOM_CHANGE, self::EXAM_PERIOD, self::SPECIAL_DAY];
    }
}
