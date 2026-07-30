<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Enum\BreakPeriodCoverage;
use App\Enum\Weekday;
use App\Repository\BreakDutyAssignmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of the break duty rota: for a whole course, this teacher watches this zone on this weekday's
 * recreo(s).
 *
 * The rota is FIXED for the course — the centre's words were "cada profe tiene su guardia durante todo
 * el curso" — so this is a weekly pattern, not a per-day record: nothing is created each morning and
 * there is no daily reshuffle like the one that covers absent colleagues ({@see GuardiaCover}).
 *
 * One row IS one guardia, whatever it spans. That is the centre's counting rule — covering both recreos
 * of the day counts as a single guardia — turned into structure: {@see $periods} says which breaks the
 * duty spans, and no query has to remember to fold two rows into one. The consequence, accepted
 * knowingly: a teacher cannot watch the patio at the first recreo and the biblioteca at the second on
 * the same day. The unique key (course, teacher, weekday) is what keeps that promise.
 *
 * What happens when the teacher is away is deliberately NOT a reassignment: the centre has nobody
 * spare, so the recreo goes uncovered and the equipo directivo is alerted to look for volunteers —
 * recorded as a {@see BreakDutyGap}.
 *
 * Change tracking is automatic: the entity is {@see Auditable}.
 */
#[ORM\Entity(repositoryClass: BreakDutyAssignmentRepository::class)]
#[ORM\Table(name: 'break_duty_assignment')]
#[ORM\Index(name: 'IDX_break_duty_year_weekday', columns: ['academic_year_id', 'weekday'])]
#[ORM\Index(name: 'IDX_break_duty_teacher', columns: ['teacher_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_break_duty_teacher_weekday', columns: ['academic_year_id', 'teacher_id', 'weekday'])]
class BreakDutyAssignment implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The course this rota line belongs to: the rota is drawn up once per course and holds all year. */
    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    /** The teacher on duty. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $teacher;

    /** The weekday of the duty, ISO-8601 (Monday = 1). */
    #[ORM\Column(name: 'weekday', type: Types::SMALLINT, enumType: Weekday::class)]
    private Weekday $weekday;

    /**
     * The zone to watch. Restricted rather than cascading on delete: a zone in use is archived, never
     * removed, so a rota line can never be left pointing nowhere.
     */
    #[ORM\ManyToOne(targetEntity: BreakZone::class)]
    #[ORM\JoinColumn(name: 'zone_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private BreakZone $zone;

    /** Which of the day's recreos the duty spans — and why both of them still count as one guardia. */
    #[ORM\Column(name: 'periods', length: 8, enumType: BreakPeriodCoverage::class)]
    private BreakPeriodCoverage $periods = BreakPeriodCoverage::BOTH;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    public function setAcademicYear(AcademicYear $academicYear): static
    {
        $this->academicYear = $academicYear;

        return $this;
    }

    public function getTeacher(): User
    {
        return $this->teacher;
    }

    public function setTeacher(User $teacher): static
    {
        $this->teacher = $teacher;

        return $this;
    }

    public function getWeekday(): Weekday
    {
        return $this->weekday;
    }

    public function setWeekday(Weekday $weekday): static
    {
        $this->weekday = $weekday;

        return $this;
    }

    public function getZone(): BreakZone
    {
        return $this->zone;
    }

    public function setZone(BreakZone $zone): static
    {
        $this->zone = $zone;

        return $this;
    }

    public function getPeriods(): BreakPeriodCoverage
    {
        return $this->periods;
    }

    public function setPeriods(BreakPeriodCoverage $periods): static
    {
        $this->periods = $periods;

        return $this;
    }

    /**
     * What this duty adds to its teacher's equitable load: the zone's weight, counted ONCE however many
     * recreos it spans, because the centre counts the day's two breaks as a single guardia.
     *
     * @return int the weighted load of this duty
     */
    public function load(): int
    {
        return $this->zone->getWeight();
    }
}
