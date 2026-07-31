<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\GuardiaQuotaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * How many guardias a teacher is expected to take on over a course: the number the equipo directivo
 * types in, not one the application works out.
 *
 * This single number carries two of the centre's rules at once, which is why there is no separate
 * notion of exemption anywhere in the model. "Las orientadoras, la PSC y el equipo directivo están
 * exentos" is a quota of zero, and "algunos profes tienen menos guardias porque tienen otras
 * complementarias" is a quota of one or two. Modelling exemption on its own would mean a second list
 * to keep in step with this one, and it could not even be derived from the role catalogue: there is no
 * orientación or PSC role in {@see \App\DataFixtures\RoleFixtures}.
 *
 * Teaching duties and recreo duties are counted separately because they come out of different pools and
 * are counted by different rules — a recreo guardia is one long break plus one short one, possibly on
 * different days — so a single figure would force the proposal engine to decide the split between them,
 * which is precisely the decision the centre reserved for itself.
 *
 * Tied to a course: the timetable and the complementarias are redrawn every September, so last year's
 * quota says nothing about this one.
 *
 * Change tracking is automatic: the entity is {@see Auditable}. Who raised whose quota, and when, is
 * exactly the kind of thing a claustro asks about in June.
 */
#[ORM\Entity(repositoryClass: GuardiaQuotaRepository::class)]
#[ORM\Table(name: 'guardia_quota')]
#[ORM\Index(name: 'IDX_guardia_quota_year', columns: ['academic_year_id'])]
#[ORM\Index(name: 'IDX_guardia_quota_teacher', columns: ['teacher_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_guardia_quota_year_teacher', columns: ['academic_year_id', 'teacher_id'])]
class GuardiaQuota implements Auditable
{
    /** The highest quota the screen offers. Nobody at the centre carries anything near this. */
    public const MAX = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The course the quota applies to. */
    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    /** The teacher the quota belongs to. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $teacher;

    /** Guardias in teaching periods this teacher takes on over the course. Zero means exempt. */
    #[ORM\Column(name: 'lective_duties', type: Types::SMALLINT, options: ['default' => 0])]
    private int $lectiveDuties = 0;

    /** Recreo guardias this teacher takes on over the course. Zero means exempt. */
    #[ORM\Column(name: 'break_duties', type: Types::SMALLINT, options: ['default' => 0])]
    private int $breakDuties = 0;

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

    public function getLectiveDuties(): int
    {
        return $this->lectiveDuties;
    }

    /**
     * Sets the teaching-period quota, clamped to the range the screen offers so that a hand-crafted
     * request cannot store a negative or absurd figure that would then skew every balance on the page.
     *
     * @param int $lectiveDuties the quota to store
     */
    public function setLectiveDuties(int $lectiveDuties): static
    {
        $this->lectiveDuties = max(0, min(self::MAX, $lectiveDuties));

        return $this;
    }

    public function getBreakDuties(): int
    {
        return $this->breakDuties;
    }

    /**
     * Sets the recreo quota, clamped like {@see setLectiveDuties()}.
     *
     * @param int $breakDuties the quota to store
     */
    public function setBreakDuties(int $breakDuties): static
    {
        $this->breakDuties = max(0, min(self::MAX, $breakDuties));

        return $this;
    }

    /**
     * Whether this teacher is exempt from guardias altogether — both quotas at zero.
     *
     * @return bool true when the teacher takes on nothing
     */
    public function isExempt(): bool
    {
        return 0 === $this->lectiveDuties && 0 === $this->breakDuties;
    }
}
