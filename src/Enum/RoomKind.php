<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What kind of space a {@see \App\Entity\Room} is. Peñalara exports no such classification (its
 * {@code <aula>} elements carry only a name and an export code), so this is centre-supplied data: it
 * exists to answer "can this class be moved here?" without a person having to know every room by heart.
 *
 * The distinction that matters for relocation is {@see isSpecialised()}: putting an ordinary lesson in
 * a lab or a workshop steals it from the department that needs its equipment, so a proposal that does
 * it should say so rather than look like any other option.
 */
enum RoomKind: string
{
    case CLASSROOM = 'classroom';
    case LAB = 'lab';
    case WORKSHOP = 'workshop';
    case COMPUTER_ROOM = 'computer_room';
    case GYM = 'gym';
    case OUTDOOR = 'outdoor';
    case LIBRARY = 'library';
    case ASSEMBLY_HALL = 'assembly_hall';
    case OTHER = 'other';

    /**
     * Human-facing name (Spanish), used in the catalogue and the free-rooms screen.
     *
     * @return string the kind's label
     */
    public function label(): string
    {
        return match ($this) {
            self::CLASSROOM => 'Aula ordinaria',
            self::LAB => 'Laboratorio',
            self::WORKSHOP => 'Taller',
            self::COMPUTER_ROOM => 'Aula de informática',
            self::GYM => 'Gimnasio',
            self::OUTDOOR => 'Pista o exterior',
            self::LIBRARY => 'Biblioteca',
            self::ASSEMBLY_HALL => 'Salón de actos',
            self::OTHER => 'Sin clasificar',
        };
    }

    /**
     * Whether the space is tied to equipment or a purpose that an ordinary lesson would displace. Used
     * to warn before an ordinary group is sent to a lab, a workshop or the computer room.
     *
     * @return bool true when occupying it has a cost beyond the space itself
     */
    public function isSpecialised(): bool
    {
        return match ($this) {
            self::LAB, self::WORKSHOP, self::COMPUTER_ROOM, self::GYM, self::LIBRARY, self::ASSEMBLY_HALL => true,
            self::CLASSROOM, self::OUTDOOR, self::OTHER => false,
        };
    }

    /**
     * The curated order in which kinds are offered and grouped in the interface.
     *
     * @return list<RoomKind> every kind, in display order
     */
    public static function inDisplayOrder(): array
    {
        return [
            self::CLASSROOM,
            self::LAB,
            self::WORKSHOP,
            self::COMPUTER_ROOM,
            self::LIBRARY,
            self::ASSEMBLY_HALL,
            self::GYM,
            self::OUTDOOR,
            self::OTHER,
        ];
    }
}
