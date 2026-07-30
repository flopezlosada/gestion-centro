<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\BreakZoneRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A place that has to be watched during the recreos: the centre's are patio, pasillo, biblioteca,
 * pistas and patio dirigido.
 *
 * Two things make a zone more than a label. It carries a {@see $weight}, because the centre was
 * explicit that not every zone costs the same to cover, so a fair rota has to balance effort and not
 * merely count turns; and a {@see $requiredTeachers}, how many people it takes, which is what tells a
 * half-covered recreo from a complete one.
 *
 * Zones are not tied to a course: they are places, and they outlive a school year. When one stops being
 * used it is {@see $archived} rather than deleted, so the rotas of past courses keep naming it while it
 * disappears from the pickers. Editable from the app on purpose — "patio dirigido" is new this coming
 * course and the weights are the centre's judgement to tune, neither of which should need a deploy.
 *
 * Change tracking is automatic: the entity is {@see Auditable}.
 */
#[ORM\Entity(repositoryClass: BreakZoneRepository::class)]
#[ORM\Table(name: 'break_zone')]
#[UniqueEntity(fields: ['name'], message: 'Ya existe una zona con ese nombre.')]
class BreakZone implements Auditable
{
    /** The lightest a zone can weigh: still a real turn, just the calmest one. */
    public const MIN_WEIGHT = 1;

    /** The heaviest a zone can weigh. A small scale keeps the numbers comparable at a glance. */
    public const MAX_WEIGHT = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The zone's name as the centre says it ("Patio", "Biblioteca"…). Unique. */
    #[ORM\Column(name: 'name', length: 80, unique: true)]
    #[Assert\NotBlank(message: 'Ponle nombre a la zona (p. ej. "Patio").')]
    #[Assert\Length(max: 80)]
    private string $name;

    /**
     * How demanding this zone is to cover, {@see MIN_WEIGHT}…{@see MAX_WEIGHT}. The equitable counter
     * adds up weights rather than turns, so whoever always gets the patio is not shown as carrying the
     * same load as whoever always gets the biblioteca.
     */
    #[ORM\Column(name: 'weight', type: Types::SMALLINT, options: ['default' => 1])]
    #[Assert\Range(
        notInRangeMessage: 'El peso de la zona va de {{ min }} (la más llevadera) a {{ max }} (la más exigente).',
        min: self::MIN_WEIGHT,
        max: self::MAX_WEIGHT,
    )]
    private int $weight = 1;

    /** How many teachers this zone needs each recreo; drives "faltan 2 personas en el patio". */
    #[ORM\Column(name: 'required_teachers', type: Types::SMALLINT, options: ['default' => 1])]
    #[Assert\Range(
        notInRangeMessage: 'El número de profesores por recreo va de {{ min }} a {{ max }}.',
        min: 1,
        max: 20,
    )]
    private int $requiredTeachers = 1;

    /** Display order in the rota grid, so the centre reads its zones in the order it thinks of them. */
    #[ORM\Column(name: 'sort_order', type: Types::SMALLINT, options: ['default' => 0])]
    private int $sortOrder = 0;

    /**
     * Whether the zone is out of use: kept for the rotas that already name it, hidden from the pickers.
     * Archiving instead of deleting is what stops a course's history from losing its zones.
     */
    #[ORM\Column(name: 'archived', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $archived = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function setWeight(int $weight): static
    {
        $this->weight = $weight;

        return $this;
    }

    public function getRequiredTeachers(): int
    {
        return $this->requiredTeachers;
    }

    public function setRequiredTeachers(int $requiredTeachers): static
    {
        $this->requiredTeachers = $requiredTeachers;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): static
    {
        $this->archived = $archived;

        return $this;
    }

    /**
     * The weight as a short human label, so a screen can say "exigente" instead of printing a number
     * the reader has to interpret.
     *
     * @return string the effort label
     */
    public function weightLabel(): string
    {
        return match (true) {
            $this->weight <= 1 => 'Llevadera',
            2 === $this->weight => 'Normal',
            3 === $this->weight => 'Exigente',
            default => 'Muy exigente',
        };
    }
}
