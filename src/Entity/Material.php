<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\MaterialRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Something of the centre that is booked rather than owned by anybody: la radio, la cámara de fotos, un
 * carro de portátiles, el móvil de extraescolares.
 *
 * A catalogue of its own, next to {@see Room}: spaces come from the timetable export and material does
 * not exist anywhere else, so somebody types it in once. Each row is ONE thing that can be in one place
 * at a time — two laptop trolleys are two rows, because "el carro B" is what you write on the board and
 * what somebody goes to fetch. A quantity column would let two people book "one of the two trolleys" and
 * then argue in the corridor about which.
 *
 * Retired material is deactivated, never deleted: the bookings already made keep their name.
 */
#[ORM\Entity(repositoryClass: MaterialRepository::class)]
#[ORM\Table(name: 'material')]
#[UniqueEntity(fields: ['name'], message: 'Ya hay material con ese nombre.')]
class Material implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, unique: true)]
    #[Assert\NotBlank(message: 'Ponle nombre al material.')]
    #[Assert\Length(max: 120)]
    private string $name = '';

    /** Where it is kept, so whoever books it knows where to go for it. */
    #[ORM\Column(name: 'kept_at', length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $keptAt = null;

    /** Anything worth knowing before taking it: "hay que devolverlo cargado", "pídeselo a conserjería". */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $notes = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

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

    public function getKeptAt(): ?string
    {
        return $this->keptAt;
    }

    public function setKeptAt(?string $keptAt): static
    {
        $keptAt = null !== $keptAt ? trim($keptAt) : '';
        $this->keptAt = '' !== $keptAt ? $keptAt : null;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $notes = null !== $notes ? trim($notes) : '';
        $this->notes = '' !== $notes ? $notes : null;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }
}
