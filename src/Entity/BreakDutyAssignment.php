<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Enum\BreakPeriod;
use App\Enum\Weekday;
use App\Repository\BreakDutyAssignmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One PLACE on the break duty rota: for a whole course, this teacher watches this zone at this recreo of
 * this weekday.
 *
 * The rota is FIXED for the course — the centre's words were "cada profe tiene su guardia durante todo
 * el curso" — so this is a weekly pattern, not a per-day record: nothing is created each morning and
 * there is no daily reshuffle like the one that covers absent colleagues ({@see GuardiaCover}).
 *
 * **A row is not a guardia.** It used to be: the first cut of this model made one row span both recreos
 * of a day, because the centre's rule was "cubrir los dos tramos cuenta como una sola guardia". The rule
 * turned out to be a different one — **a guardia is one long recreo plus one short one, and they may
 * fall on different days** — so a row is now one place and the guardia is a count over places
 * ({@see BreakDutyRoster}). That also lifts the restriction the old shape imposed: somebody can watch
 * the patio at the long recreo and the biblioteca at the short one, which the centre asked for.
 *
 * The unique key is (course, teacher, weekday, period): nobody can be in two zones at the same recreo,
 * which is the only thing physically impossible here.
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
#[ORM\Index(name: 'IDX_break_duty_zone', columns: ['zone_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_break_duty_teacher_period', columns: ['academic_year_id', 'teacher_id', 'weekday', 'period'])]
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

    /** Which of the day's two recreos this place is for. */
    #[ORM\Column(name: 'period', length: 8, enumType: BreakPeriod::class)]
    private BreakPeriod $period = BreakPeriod::FIRST;

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

    public function getPeriod(): BreakPeriod
    {
        return $this->period;
    }

    public function setPeriod(BreakPeriod $period): static
    {
        $this->period = $period;

        return $this;
    }

    /**
     * What this place adds to its teacher's equitable load: the zone's weight.
     *
     * Per PLACE, not per guardia. Two places make a guardia, and pairing them is
     * {@see BreakDutyRoster}'s job; weighing them one by one is what keeps somebody who does two spells
     * in the patio from counting the same as somebody who does two in the biblioteca.
     *
     * @return int the weighted load of this place
     */
    public function load(): int
    {
        return $this->zone->getWeight();
    }
}
