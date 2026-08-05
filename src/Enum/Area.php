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
 * the administration back-office, the guardia coordination screen (managing the daily parte), the
 * space management module (the room catalogue and, from there on, room changes), the copy room's
 * queue of orders and the TIC incident register — where REPORTING one is open to everybody and the
 * permission only gates dealing with them.
 *
 * {@see self::FOTOCOPIAS} follows that same TIC shape, and that is the reason it exists as an area at
 * all: PLACING an order is open to the whole claustro (the menu entry has no gate), and the permission
 * governs only the other side of the counter — seeing everybody's queue and marking each order printed.
 * Before it existed that side was keyed to write access on {@see self::GUARDIAS}, which is the wrong
 * question: it would have handed the auxiliares de control the whole daily parte just to let them see
 * what they have to photocopy.
 */
enum Area: string
{
    case ADMINISTRATION = 'administration';
    case GUARDIAS = 'guardias';
    case ESPACIOS = 'espacios';
    case FOTOCOPIAS = 'fotocopias';
    case TIC = 'tic';

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
            self::ESPACIOS => 'Espacios',
            self::FOTOCOPIAS => 'Fotocopias',
            self::TIC => 'Incidencias TIC',
        };
    }

    /**
     * Name of the module's index route, so screens can deep-link to where the area is worked on.
     *
     * OJO: hoy no tiene ni un llamador (comprobado con grep sobre `src/` y `templates/`). Decía ser la
     * fuente única del resaltado del menú y ya no lo es: `app_shell.html.twig` enumera las áreas a mano
     * con `is_granted(...)` bloque a bloque. Se conserva porque el mapa área → pantalla es dato útil y
     * cuesta nada, pero no hay que creerse que cambiarlo mueva nada en la interfaz.
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
            self::ESPACIOS => 'space_index',
            self::FOTOCOPIAS => 'copy_request_index',
            self::TIC => 'tic_incident_index',
        };
    }

    /**
     * The curated order in which areas are presented across the application (menu, overviews).
     *
     * @return list<Area> every area, in display order
     */
    public static function inDisplayOrder(): array
    {
        return [self::ADMINISTRATION, self::GUARDIAS, self::ESPACIOS, self::FOTOCOPIAS, self::TIC];
    }
}
