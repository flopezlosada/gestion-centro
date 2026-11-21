<?php

declare(strict_types=1);

namespace App\Booking;

use App\Entity\Material;
use App\Entity\Room;
use App\Enum\RoomKind;

/**
 * The bookable things grouped the way the centre talks about them: the laptop carts (material with counted
 * devices), the computer rooms, the rest of the material and the rest of the spaces. Both the booking grid
 * and the fixed bookings screen offer the catalogue in these groups, so the grouping lives in one place.
 */
final class BookableCatalogue
{
    private const array LABELS = [
        'carros' => 'Carros de portátiles',
        'informatica' => 'Aulas de informática',
        'material' => 'Otro material',
        'espacios' => 'Otros espacios',
    ];

    /**
     * The groups in display order. A group with nothing in it is left out: a heading with nothing under it
     * is noise.
     *
     * @param list<Material> $materials the active material
     * @param list<Room>     $rooms     the reservable rooms
     *
     * @return array<string, array{label: string, items: list<Material|Room>}> group key → its label and things
     */
    public static function groups(array $materials, array $rooms): array
    {
        $items = [
            'carros' => array_values(array_filter($materials, static fn (Material $m): bool => null !== $m->getDeviceCount())),
            'informatica' => array_values(array_filter($rooms, static fn (Room $r): bool => RoomKind::COMPUTER_ROOM === $r->getKind())),
            'material' => array_values(array_filter($materials, static fn (Material $m): bool => null === $m->getDeviceCount())),
            'espacios' => array_values(array_filter($rooms, static fn (Room $r): bool => RoomKind::COMPUTER_ROOM !== $r->getKind())),
        ];

        $groups = [];
        foreach ($items as $key => $things) {
            if ([] !== $things) {
                $groups[$key] = ['label' => self::LABELS[$key], 'items' => $things];
            }
        }

        return $groups;
    }
}
