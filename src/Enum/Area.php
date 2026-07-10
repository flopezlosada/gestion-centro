<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Functional area of the system over which access (read/write) is granted per role.
 *
 * This catalog grows as each module is built; only areas that have a real module are listed,
 * so the permission matrix never shows knobs for features that do not exist yet. Administrative
 * screens (users, roles, activity trail) are gated by the role's admin flag, not by an area.
 */
enum Area: string
{
    case TASK = 'task';
    case TEMPLATE = 'template';
    case ORGANIZATION = 'organization';
    case CALENDAR = 'calendar';

    /**
     * Human-facing area name (Spanish), used in the permissions matrix.
     *
     * @return string the area label
     */
    public function label(): string
    {
        return match ($this) {
            self::TASK => 'Tareas',
            self::TEMPLATE => 'Plantillas de tareas',
            self::ORGANIZATION => 'Organigrama',
            self::CALENDAR => 'Calendario',
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
            self::TASK => 'task_index',
            self::TEMPLATE => 'task_template_index',
            self::ORGANIZATION => 'organization_index',
            self::CALENDAR => 'calendar_index',
        };
    }

    /**
     * The curated order in which areas are presented across the application (menu, overviews).
     *
     * @return list<Area> every area, in display order
     */
    public static function inDisplayOrder(): array
    {
        return [self::TASK, self::TEMPLATE, self::CALENDAR, self::ORGANIZATION];
    }
}
