<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Functional area of the system over which access (read/write) is granted per role, through the
 * permission matrix ({@see \App\Entity\Role} + {@see \App\Security\Voter\AreaVoter}).
 *
 * Only areas that genuinely require a permission live here. Tasks and the calendar are NOT areas:
 * they are visible to everyone and filtered by the chain of command, so they carry no matrix knob.
 * This catalog grows as new gated modules appear. Administration (users, roles, org chart, calendar
 * of non-teaching days) is the first such area: it lets Direction manage the centre without being a
 * superuser, while the role's admin flag still bypasses the matrix entirely.
 */
enum Area: string
{
    case ADMINISTRATION = 'administration';

    /**
     * Human-facing area name (Spanish), used in the permissions matrix.
     *
     * @return string the area label
     */
    public function label(): string
    {
        return match ($this) {
            self::ADMINISTRATION => 'Administración',
        };
    }

    /**
     * Name of the module's index route, so screens can deep-link to where the area is worked on.
     *
     * Convention: the route name ends in '_index'.
     *
     * @return string the Symfony route name of the area's index page (ends in '_index')
     */
    public function indexRoute(): string
    {
        return match ($this) {
            self::ADMINISTRATION => 'admin_user_index',
        };
    }

    /**
     * The curated order in which areas are presented across the application (menu, overviews).
     *
     * @return list<Area> every area, in display order
     */
    public static function inDisplayOrder(): array
    {
        return [self::ADMINISTRATION];
    }
}
