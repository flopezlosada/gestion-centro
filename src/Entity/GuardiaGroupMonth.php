<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GuardiaGroupMonthRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * How many periods a group spent without its teacher in a month, counted from the guardias that were
 * archived ({@see ArchivedGuardiaCover}) — the one figure about absences the centre keeps past the plazo.
 *
 * By group and month and nothing finer, on purpose. A day or a period, combined with the timetable, says
 * which teacher was away; so would the room or the subject, which in a small centre only one teacher has
 * for a group. A month of a group's lost periods says how its year went and no more.
 *
 * Written only by {@see \App\Guardia\AbsencePurger}, which adds to the row as each day is archived.
 */
#[ORM\Entity(repositoryClass: GuardiaGroupMonthRepository::class, readOnly: true)]
#[ORM\Table(name: 'guardia_group_month')]
#[ORM\UniqueConstraint(name: 'UNIQ_guardia_group_month', columns: ['group_name', 'month_start'])]
class GuardiaGroupMonth
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** One group, as the timetable names it (as long as {@see ScheduleEntry::$groupName}): a cover naming several ("1A, 1B") adds to each. */
    #[ORM\Column(name: 'group_name', length: 64)]
    private string $groupName;

    /** The first day of the month. */
    #[ORM\Column(name: 'month_start', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $month;

    /** The periods the group was without its teacher that month, covered or not. */
    #[ORM\Column(name: 'periods')]
    private int $periods = 0;

    /** The row id, null until stored. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** The group. */
    public function getGroupName(): string
    {
        return $this->groupName;
    }

    /** The first day of the month. */
    public function getMonth(): \DateTimeImmutable
    {
        return $this->month;
    }

    /** The periods the group was without its teacher that month. */
    public function getPeriods(): int
    {
        return $this->periods;
    }
}
