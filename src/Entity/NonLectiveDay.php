<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\NonLectiveDayRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A single non-teaching day of the school calendar (a public holiday, a local festivity, a one-off
 * closure…). Together with weekends — which are non-teaching by definition and not stored here —
 * these are the days on which no task deadline may fall; see {@see \App\Service\SchoolCalendar}.
 *
 * One row per date (the date is unique): a day either is non-teaching or it is not. Vacation ranges
 * are modelled as individual days for now (bulk ranges are a follow-up).
 */
#[ORM\Entity(repositoryClass: NonLectiveDayRepository::class)]
#[ORM\Table(name: 'non_lective_day')]
#[UniqueEntity(fields: ['date'], message: 'Ya existe un día no lectivo con esa fecha.')]
class NonLectiveDay implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The non-teaching date. Unique: the same day cannot be registered twice. */
    #[ORM\Column(name: 'date', type: Types::DATE_IMMUTABLE, unique: true)]
    #[Assert\NotNull(message: 'Indica la fecha del día no lectivo.')]
    private \DateTimeImmutable $date;

    /** Human-readable reason shown in the calendar (e.g. "Fiesta local", "Vacaciones de Navidad"). */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Describe el día no lectivo (p. ej. "Fiesta local").')]
    #[Assert\Length(max: 120)]
    private string $description;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }
}
