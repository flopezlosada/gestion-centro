<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Whose ordinary timetable stops applying while a {@see \App\Entity\SpacePlan} is in force.
 *
 * This single field is what lets ONE mechanism cover the centre's three cases, instead of three
 * modules:
 *
 *  - {@see NONE} — a plain room change. Everybody still has their lessons; only the room changes.
 *  - {@see GROUPS} — exam week for 2º de Bachillerato. Those groups have no ordinary lessons those
 *    days (they are sitting exams); everybody else carries on, and the classes their exam rooms
 *    displaced get relocated.
 *  - {@see WHOLE_CENTRE} — cultural days. That day there is no ordinary timetable at all: what happens
 *    is what the plan says.
 */
enum SubstitutionScope: string
{
    /** Nobody's timetable is replaced; lessons happen as usual, somewhere else. */
    case NONE = 'none';

    /** The listed groups have no ordinary lessons during the plan's dates and periods. */
    case GROUPS = 'groups';

    /** Nobody has ordinary lessons: the plan replaces the timetable for the whole centre. */
    case WHOLE_CENTRE = 'whole_centre';

    /**
     * Human-facing name (Spanish).
     *
     * @return string the scope label
     */
    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Nadie: solo cambia el aula',
            self::GROUPS => 'Solo los grupos que indique',
            self::WHOLE_CENTRE => 'Todo el centro',
        };
    }

    /**
     * One line explaining what it does to the timetable.
     *
     * @return string the help text
     */
    public function help(): string
    {
        return match ($this) {
            self::NONE => 'Las clases se dan igual, en otro sitio.',
            self::GROUPS => 'Esos grupos no tienen sus clases habituales esos días (por ejemplo, están de exámenes).',
            self::WHOLE_CENTRE => 'Ese día no hay horario ordinario: solo lo que diga este plan.',
        };
    }

    /**
     * Whether a group's ordinary lessons are replaced under this scope.
     *
     * @param string             $groupName  the group to check
     * @param list<string>       $scopeGroups the groups named by the plan (only meaningful for {@see GROUPS})
     *
     * @return bool true when that group has no ordinary lesson while the plan is in force
     */
    public function replaces(string $groupName, array $scopeGroups): bool
    {
        return match ($this) {
            self::NONE => false,
            self::WHOLE_CENTRE => true,
            self::GROUPS => \in_array($groupName, $scopeGroups, true),
        };
    }

    /**
     * The curated order in which scopes are offered.
     *
     * @return list<SubstitutionScope> every scope, in display order
     */
    public static function inDisplayOrder(): array
    {
        return [self::NONE, self::GROUPS, self::WHOLE_CENTRE];
    }
}
