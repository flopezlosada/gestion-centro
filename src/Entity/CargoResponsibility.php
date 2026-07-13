<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The task is the job of whoever holds a unit's post — its {@see Unit::$manager} (e.g. "the head of
 * Maths"). Resolved live: if the head changes, open tasks follow the new head automatically, because
 * nothing is copied. This is the shape that fulfils "the task must follow the post-holder".
 */
#[ORM\Entity]
class CargoResponsibility extends TaskResponsibility
{
    /** The unit whose current manager is responsible. Nullable so deleting the unit does not cascade. */
    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(name: 'unit_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Unit $unit;

    public function __construct(Unit $unit)
    {
        $this->unit = $unit;
    }

    public function getUnit(): ?Unit
    {
        return $this->unit;
    }

    public function holders(): array
    {
        $manager = $this->unit?->getManager();

        return null !== $manager && $manager->isActive() ? [$manager] : [];
    }

    public function label(): string
    {
        return null !== $this->unit ? 'Jefatura de '.$this->unit->getName() : 'Jefatura (unidad eliminada)';
    }
}
