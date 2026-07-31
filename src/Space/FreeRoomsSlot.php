<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\Room;

/**
 * One period of the "aulas libres" sheet: when it runs, whether it is a recreo, and which spaces nobody
 * has then — already grouped into the tiers the screen prints.
 *
 * The tiers are decided here and not in Twig because the question the screen answers is "¿dónde caben
 * estos dos grupos?", and that question sorts the free rooms into three DIFFERENT kinds of answer:
 * candidates (two groups or more fit), maybes (nobody has ever said how big the room is) and
 * non-candidates (one group, or not even one). Deciding that with `{% if %}`s over a flat list is how
 * the three end up looking alike, which is the bug the redesign exists to fix.
 */
final readonly class FreeRoomsSlot
{
    /**
     * How many groups a room must hold to be worth offering for a merge. Two, by definition of the job:
     * putting two classes together needs a room that takes both.
     */
    private const int MERGEABLE_FROM = 2;

    /**
     * @param int                $index    the period's index within the day
     * @param \DateTimeImmutable $startsAt when the period starts
     * @param \DateTimeImmutable $endsAt   when it ends
     * @param bool               $isBreak  whether it is a recreo rather than teaching time
     * @param list<Room>         $rooms    the free assignable spaces, roomiest first
     */
    public function __construct(
        public int $index,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public bool $isBreak,
        public array $rooms,
    ) {
    }

    /**
     * How many spaces are free — the number on the period's tab.
     *
     * @return int the count of free spaces
     */
    public function count(): int
    {
        return \count($this->rooms);
    }

    /**
     * Whether the period has anything to offer, which is what tells the screen to show the sheet or the
     * empty state.
     *
     * @return bool true when at least one space is free
     */
    public function hasRooms(): bool
    {
        return [] !== $this->rooms;
    }

    /**
     * The free spaces grouped by how many groups they hold, in the order the screen reads them: the
     * candidates roomiest first, then the rooms of unknown size, and last — dimmed — the ones that do not
     * take two groups.
     *
     * A room of unknown size goes between the two and NOT with the small ones on purpose: nobody has
     * measured it, so it may well be the biggest room free. Dropping it into "no sirve para juntar" would
     * turn a missing datum into a verdict against the room.
     *
     * @return list<array{label: string, note: ?string, dimmed: bool, rooms: list<Room>}> the tiers, in display order
     */
    public function tiers(): array
    {
        $known = [];
        $unknown = [];
        foreach ($this->rooms as $room) {
            $groups = $room->effectiveSize()?->groups();
            if (null === $groups) {
                $unknown[] = $room;
                continue;
            }

            $known[$groups][] = $room;
        }

        krsort($known);

        $tiers = [];
        foreach ($known as $groups => $rooms) {
            if ($groups >= self::MERGEABLE_FROM) {
                $tiers[] = ['label' => sprintf('Caben %d grupos', $groups), 'note' => null, 'dimmed' => false, 'rooms' => $rooms];
            }
        }

        if ([] !== $unknown) {
            $tiers[] = ['label' => 'Tamaño sin indicar', 'note' => 'puede que sirva: nadie lo ha dicho', 'dimmed' => false, 'rooms' => $unknown];
        }

        foreach ($known as $groups => $rooms) {
            if ($groups >= self::MERGEABLE_FROM) {
                continue;
            }

            $tiers[] = 1 === $groups
                ? ['label' => 'Cabe 1 grupo', 'note' => 'no sirve para juntar', 'dimmed' => true, 'rooms' => $rooms]
                : ['label' => 'No cabe un grupo entero', 'note' => 'aulas de desdoble', 'dimmed' => true, 'rooms' => $rooms];
        }

        return $tiers;
    }
}
