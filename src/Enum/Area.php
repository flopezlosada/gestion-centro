<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Functional area of the system over which access (read/write) is granted per role, through the
 * {@see \App\Entity\Role} permission matrix and the {@see \App\Security\Voter\AreaVoter}.
 *
 * This catalog only lists areas that gate a real, permissioned module. It deliberately excludes
 * Tasks and the calendar: those are universally accessible and scoped by the organisation chart
 * instead (see {@see \App\Service\TaskVisibility}), not by this matrix. Matrix-gated areas today are
 * the administration back-office and the guardia coordination screen (managing the daily parte); the
 * enum is kept ready to grow as future modules appear.
 */
enum Area: string
{
    case ADMINISTRATION = 'administration';
    case GUARDIAS = 'guardias';

    /**
     * Human-facing area name (Spanish), used in the permissions matrix.
     *
     * @return string the area label
     */
    public function label(): string
    {
        return match ($this) {
            self::ADMINISTRATION => 'Administración',
            self::GUARDIAS => 'Guardias',
        };
    }

    /**
     * Name of the module's index route, so screens can deep-link to where the area is worked on.
     * Single source of truth for the sidebar menu's active-item highlight.
     *
     * Convention: the route name ends in '_index'.
     *
     * @return string the Symfony route name of the area's index page (ends in '_index')
     */
    public function indexRoute(): string
    {
        return match ($this) {
            self::ADMINISTRATION => 'admin_user_index',
            self::GUARDIAS => 'guardia_index',
        };
    }

    /**
     * The curated order in which areas are presented across the application (menu, overviews).
     *
     * @return list<Area> every area, in display order
     */
    public static function inDisplayOrder(): array
    {
        return [self::ADMINISTRATION, self::GUARDIAS];
    }
}
