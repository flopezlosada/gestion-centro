<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArchivedGuardiaCoverRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A guardia from before the plazo the centre keeps absences for ({@see \App\Enum\AbsenceRetention}): what
 * is left of a {@see GuardiaCover} once nobody may know any longer whom it covered.
 *
 * A table of its own, and not a cover with its absent teacher emptied, so that the schema is what keeps
 * the promise. There is no column here for the absent teacher, the group, the room, the subject, the task
 * or what happened, so none of them can be left behind by a query that forgot one; and a live cover keeps
 * always having an absent teacher, which every screen of the parte relies on.
 *
 * What stays is what the fair split needs: the day and period, who did the guardia and whether it took
 * place. {@see $groupingKey} keeps the covers that were minded together counting as one guardia, as they
 * did while live ({@see \App\Repository\GuardiaCoverRepository::WORK_UNIT}).
 *
 * Written only by {@see \App\Guardia\AbsencePurger}, in bulk, so there are no setters. Not
 * {@see \App\Contract\Auditable}: its trail would say again what this row says, and the cover's own trail
 * is deleted with it.
 */
#[ORM\Entity(repositoryClass: ArchivedGuardiaCoverRepository::class, readOnly: true)]
#[ORM\Table(name: 'guardia_cover_archive')]
#[ORM\Index(name: 'IDX_cover_archive_date', columns: ['cover_date'])]
#[ORM\Index(name: 'IDX_cover_archive_assigned', columns: ['assigned_guardia_id'])]
class ArchivedGuardiaCover
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The day of the guardia. */
    #[ORM\Column(name: 'cover_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** The period within the day, as {@see GuardiaCover::$slotIndex}. */
    #[ORM\Column(name: 'slot_index', type: Types::SMALLINT)]
    private int $slotIndex;

    /** Who did the guardia, or null when nobody was assigned. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_guardia_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedGuardia = null;

    /** Whether it ended as an incident, so it is not counted as done ({@see GuardiaCover::$notCovered}). */
    #[ORM\Column(name: 'not_covered', type: Types::BOOLEAN)]
    private bool $notCovered = false;

    /**
     * The grouping the cover was part of, as the id it had. Only an id and not a link: the grouping says in
     * which room, and it is deleted with the covers. All that is kept is that these covers went together.
     */
    #[ORM\Column(name: 'grouping_key', nullable: true)]
    private ?int $groupingKey = null;

    /** The row id, null until stored. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** The day of the guardia. */
    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    /** The period within the day. */
    public function getSlotIndex(): int
    {
        return $this->slotIndex;
    }

    /** Who did the guardia, or null when nobody was assigned. */
    public function getAssignedGuardia(): ?User
    {
        return $this->assignedGuardia;
    }

    /** Whether it ended as an incident. */
    public function isNotCovered(): bool
    {
        return $this->notCovered;
    }

    /**
     * Whether the cover was minded together with others in one room.
     *
     * @return bool true when it was part of a grouping
     */
    public function isGrouped(): bool
    {
        return null !== $this->groupingKey;
    }
}
