<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Enum\CategoryColor;
use App\Repository\EventCategoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An admin-managed, centre-wide label used to colour-code personal agenda events (e.g. "Tutoría",
 * "Reunión de departamento"). Global on purpose: it is a shared catalogue the admin curates, unlike
 * the {@see PersonalEvent}s themselves, which are private per user. Its colour is picked from a fixed
 * palette ({@see CategoryColor}); the actual colour token lives in the stylesheet.
 *
 * {@see Auditable} like the other admin catalogues, so changes are logged automatically.
 */
#[ORM\Entity(repositoryClass: EventCategoryRepository::class)]
#[ORM\Table(name: 'event_category')]
#[ORM\UniqueConstraint(name: 'uniq_event_category_name', columns: ['name'])]
#[UniqueEntity(fields: ['name'], message: 'Ya existe una categoría con ese nombre.')]
class EventCategory implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Ponle un nombre a la categoría.')]
    #[Assert\Length(max: 50)]
    private string $name;

    /** Colour from the fixed palette; defaults to a neutral grey. */
    #[ORM\Column(length: 20, enumType: CategoryColor::class)]
    private CategoryColor $color = CategoryColor::SLATE;

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
        $this->name = $name;

        return $this;
    }

    public function getColor(): CategoryColor
    {
        return $this->color;
    }

    public function setColor(CategoryColor $color): static
    {
        $this->color = $color;

        return $this;
    }
}
