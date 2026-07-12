<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\AcademicYearRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The dated structure of one school year: the start and end of each of the three terms
 * (trimestres). Set once per course by the administration.
 *
 * In a term-based Spanish IES the holiday breaks are simply the gaps between terms, so these six
 * dates are enough to answer every calendar anchor a task deadline may need: the start/end of a
 * term, the start/end of the year (term 1 start / term 3 end) and "before/after a break" (the
 * neighbouring term boundary). Individual non-teaching days still live in {@see NonLectiveDay};
 * this entity adds the term skeleton on top.
 *
 * Change tracking is automatic: the entity is {@see Auditable}.
 */
#[ORM\Entity(repositoryClass: AcademicYearRepository::class)]
#[ORM\Table(name: 'academic_year')]
#[UniqueEntity(fields: ['schoolYear'], message: 'Ya existe la estructura de ese curso.')]
#[Assert\Callback('validateTermOrder')]
class AcademicYear implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The course this structure describes, in "YYYY-YYYY" form (e.g. "2026-2027"). Unique. */
    #[ORM\Column(name: 'school_year', length: 9, unique: true)]
    #[Assert\NotBlank(message: 'Indica el curso (p. ej. "2026-2027").')]
    #[Assert\Regex(pattern: '/^\d{4}-\d{4}$/', message: 'El curso debe tener el formato "2026-2027".')]
    private string $schoolYear;

    /** First teaching day of the first term. */
    #[ORM\Column(name: 'term1_start', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Indica el inicio del primer trimestre.')]
    private \DateTimeImmutable $term1Start;

    /** Last teaching day of the first term (typically the day before the Christmas break). */
    #[ORM\Column(name: 'term1_end', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Indica el fin del primer trimestre.')]
    private \DateTimeImmutable $term1End;

    /** First teaching day of the second term (typically after the Christmas break). */
    #[ORM\Column(name: 'term2_start', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Indica el inicio del segundo trimestre.')]
    private \DateTimeImmutable $term2Start;

    /** Last teaching day of the second term (typically the day before the Easter break). */
    #[ORM\Column(name: 'term2_end', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Indica el fin del segundo trimestre.')]
    private \DateTimeImmutable $term2End;

    /** First teaching day of the third term (typically after the Easter break). */
    #[ORM\Column(name: 'term3_start', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Indica el inicio del tercer trimestre.')]
    private \DateTimeImmutable $term3Start;

    /** Last teaching day of the third term (the last day of the course). */
    #[ORM\Column(name: 'term3_end', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Indica el fin del tercer trimestre.')]
    private \DateTimeImmutable $term3End;

    /**
     * Validates that the six dates are strictly ordered: each term starts on or before it ends, and
     * every term ends before the next one starts. Enforced as a class constraint so an overlapping or
     * out-of-order course structure can never be persisted.
     *
     * @param ExecutionContextInterface $context the validation context to report violations to
     */
    public function validateTermOrder(ExecutionContextInterface $context): void
    {
        // Skip until every date is present; the per-field NotNull constraints report those.
        if (!isset($this->term1Start, $this->term1End, $this->term2Start, $this->term2End, $this->term3Start, $this->term3End)) {
            return;
        }

        $steps = [
            ['term1Start', $this->term1Start, 'term1End', $this->term1End],
            ['term1End', $this->term1End, 'term2Start', $this->term2Start],
            ['term2Start', $this->term2Start, 'term2End', $this->term2End],
            ['term2End', $this->term2End, 'term3Start', $this->term3Start],
            ['term3Start', $this->term3Start, 'term3End', $this->term3End],
        ];

        foreach ($steps as [$fromLabel, $from, $toField, $to]) {
            if ($from > $to) {
                $context->buildViolation('Las fechas de los trimestres deben ir en orden: cada inicio antes de su fin y cada trimestre antes del siguiente.')
                    ->atPath($toField)
                    ->addViolation();

                return;
            }
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSchoolYear(): string
    {
        return $this->schoolYear;
    }

    public function setSchoolYear(string $schoolYear): static
    {
        $this->schoolYear = $schoolYear;

        return $this;
    }

    public function getTerm1Start(): \DateTimeImmutable
    {
        return $this->term1Start;
    }

    public function setTerm1Start(\DateTimeImmutable $term1Start): static
    {
        $this->term1Start = $term1Start;

        return $this;
    }

    public function getTerm1End(): \DateTimeImmutable
    {
        return $this->term1End;
    }

    public function setTerm1End(\DateTimeImmutable $term1End): static
    {
        $this->term1End = $term1End;

        return $this;
    }

    public function getTerm2Start(): \DateTimeImmutable
    {
        return $this->term2Start;
    }

    public function setTerm2Start(\DateTimeImmutable $term2Start): static
    {
        $this->term2Start = $term2Start;

        return $this;
    }

    public function getTerm2End(): \DateTimeImmutable
    {
        return $this->term2End;
    }

    public function setTerm2End(\DateTimeImmutable $term2End): static
    {
        $this->term2End = $term2End;

        return $this;
    }

    public function getTerm3Start(): \DateTimeImmutable
    {
        return $this->term3Start;
    }

    public function setTerm3Start(\DateTimeImmutable $term3Start): static
    {
        $this->term3Start = $term3Start;

        return $this;
    }

    public function getTerm3End(): \DateTimeImmutable
    {
        return $this->term3End;
    }

    public function setTerm3End(\DateTimeImmutable $term3End): static
    {
        $this->term3End = $term3End;

        return $this;
    }

    /**
     * First teaching day of the course (the start of the first term).
     *
     * @return \DateTimeImmutable the year's start date
     */
    public function getYearStart(): \DateTimeImmutable
    {
        return $this->term1Start;
    }

    /**
     * Last teaching day of the course (the end of the third term).
     *
     * @return \DateTimeImmutable the year's end date
     */
    public function getYearEnd(): \DateTimeImmutable
    {
        return $this->term3End;
    }

    /**
     * The first teaching day of the given term.
     *
     * @param int $term the term number, 1 to 3
     *
     * @return \DateTimeImmutable the term's start date
     */
    public function getTermStart(int $term): \DateTimeImmutable
    {
        return match ($term) {
            1 => $this->term1Start,
            2 => $this->term2Start,
            3 => $this->term3Start,
            default => throw new \InvalidArgumentException(sprintf('Trimestre inválido: %d (debe ser 1, 2 o 3).', $term)),
        };
    }

    /**
     * The last teaching day of the given term.
     *
     * @param int $term the term number, 1 to 3
     *
     * @return \DateTimeImmutable the term's end date
     */
    public function getTermEnd(int $term): \DateTimeImmutable
    {
        return match ($term) {
            1 => $this->term1End,
            2 => $this->term2End,
            3 => $this->term3End,
            default => throw new \InvalidArgumentException(sprintf('Trimestre inválido: %d (debe ser 1, 2 o 3).', $term)),
        };
    }
}
