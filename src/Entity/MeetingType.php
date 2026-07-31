<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\MeetingTypeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A kind of staff meeting: CCP, tutores, equipo directivo, AMPA/AFA, agentes externos, and one per
 * working commission of the centre. It is what the minutes are then filed under.
 *
 * A TABLE and not an enum, on the centre's explicit request — "podemos tener la posibilidad desde
 * administración de modificar estos desplegables y así no depender de ti" — and because it is safe to do
 * so: unlike {@see \App\Enum\MeetingScope}, a kind only changes what the meeting is CALLED and how the
 * archive groups it. Nothing in the application branches on which kind it is, so adding "Comisión de
 * biblioteca" next October cannot break anything.
 *
 * Retired kinds are deactivated, never deleted: the minutes already filed under them keep their label.
 */
#[ORM\Entity(repositoryClass: MeetingTypeRepository::class)]
#[ORM\Table(name: 'meeting_type')]
#[UniqueEntity(fields: ['name'], message: 'Ya existe un tipo de reunión con ese nombre.')]
class MeetingType implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank(message: 'Ponle nombre al tipo de reunión.')]
    #[Assert\Length(max: 100)]
    private string $name = '';

    /**
     * Whether the minutes of this kind have to be read and approved at the following meeting. It is the
     * DEFAULT for a new meeting of this kind, not a rule: the centre said a CCP or a department meeting
     * approves its acta and the rest do not, and this stops whoever convenes from having to remember it
     * every single time. The meeting keeps its own flag, so an exception is still one tick away.
     */
    #[ORM\Column(name: 'minutes_approval_required', options: ['default' => false])]
    private bool $minutesApprovalRequired = false;

    /** Retired kinds stay for the archive but are no longer offered when convening. */
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

    public function isMinutesApprovalRequired(): bool
    {
        return $this->minutesApprovalRequired;
    }

    public function setMinutesApprovalRequired(bool $required): static
    {
        $this->minutesApprovalRequired = $required;

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
