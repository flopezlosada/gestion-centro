<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AbsenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A teacher being away on a given day. It groups the {@see GuardiaCover} lines generated for each
 * period they would have taught, and holds the one piece of information that belongs to the absence
 * as a whole rather than to any single period: the {@see $reason} it happened.
 *
 * The reason is PRIVATE: only the absent teacher and the guardia coordinator may read it — never the
 * guardia teacher who covers a class (they get the task and its document, not why a colleague is
 * away). Keeping it here, in a single row per (teacher, day), is what makes that privacy enforceable
 * and keeps the reason from ever diverging between the day's periods — the footgun a per-cover copy
 * would create.
 *
 * The absent teacher and date are also snapshotted onto each {@see GuardiaCover} (alongside its group
 * and room), so the parte and every analytics query keep reading per period without a join; those
 * fields are written together with this absence at registration time and never drift.
 */
#[ORM\Entity(repositoryClass: AbsenceRepository::class)]
#[ORM\Table(name: 'guardia_absence')]
#[ORM\UniqueConstraint(name: 'UNIQ_guardia_absence', columns: ['absent_teacher_id', 'absence_date'])]
class Absence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The teacher who is away. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'absent_teacher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $absentTeacher;

    /** The day of the absence. */
    #[ORM\Column(name: 'absence_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /**
     * Why the teacher is away, filled by the teacher themselves or the coordinator. PRIVATE: shown
     * only on the coordinator's surfaces and to the absent teacher, never to the covering guardia.
     * Nullable — a last-minute absence may be registered without a reason.
     */
    #[ORM\Column(name: 'reason', type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAbsentTeacher(): User
    {
        return $this->absentTeacher;
    }

    public function setAbsentTeacher(User $absentTeacher): static
    {
        $this->absentTeacher = $absentTeacher;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = null !== $reason && '' !== trim($reason) ? trim($reason) : null;

        return $this;
    }
}
