<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArchivedBreakDutyGapRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A recreo left unwatched before the plazo the centre keeps absences for ({@see \App\Enum\AbsenceRetention}):
 * what is left of a {@see BreakDutyGap} once nobody may know any longer who was away.
 *
 * A table of its own for the same reason as {@see ArchivedGuardiaCover}: there is no column for the rota
 * line, so the zone, the recreo and the teacher on duty cannot be left behind. The rota is fixed, so any
 * of those with the day says who was missing. The note goes too: it is free text about that day.
 *
 * What stays is what "cuántos recreos se quedaron sin vigilar" needs: the course, the day and who stepped
 * in, if anybody did.
 *
 * Written only by {@see \App\Guardia\AbsencePurger}, in bulk, so there are no setters. Not
 * {@see \App\Contract\Auditable}: the gap's own trail is deleted with it.
 */
#[ORM\Entity(repositoryClass: ArchivedBreakDutyGapRepository::class, readOnly: true)]
#[ORM\Table(name: 'break_duty_gap_archive')]
#[ORM\Index(name: 'IDX_break_gap_archive_year', columns: ['academic_year_id'])]
#[ORM\Index(name: 'IDX_break_gap_archive_volunteer', columns: ['volunteer_id'])]
class ArchivedBreakDutyGap
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The course whose rota the recreo belonged to. Deleting the course takes its archive with it. */
    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    /** The day the recreo went unwatched. */
    #[ORM\Column(name: 'gap_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** Who stepped in, or null when nobody did. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'volunteer_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $volunteer = null;

    /** The row id, null until stored. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** The course whose rota the recreo belonged to. */
    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    /** The day the recreo went unwatched. */
    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    /** Who stepped in, or null when nobody did. */
    public function getVolunteer(): ?User
    {
        return $this->volunteer;
    }

    /**
     * Whether somebody ended up watching the zone after all.
     *
     * @return bool true when a volunteer stepped in
     */
    public function isCovered(): bool
    {
        return null !== $this->volunteer;
    }
}
