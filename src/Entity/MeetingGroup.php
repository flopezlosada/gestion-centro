<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\MeetingGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A standing list of people convened together often — tutores de 2º ESO, la CCP — so whoever convenes
 * that meeting picks it once instead of ticking the same names every time.
 *
 * Deliberately NOT {@see Project}: a project grants its coordinator the right to convene and archives
 * its own minutes, neither of which applies here. This is nothing but a saved roster, applied as a
 * one-off prefill of attendees ({@see \App\Controller\MeetingController::new()}) — editing the meeting's
 * attendees afterwards never touches the group, and editing the group never touches a meeting already
 * convened from it.
 *
 * Retired groups are deactivated, never deleted: same reasoning as {@see Project} and {@see MeetingType}.
 */
#[ORM\Entity(repositoryClass: MeetingGroupRepository::class)]
#[ORM\Table(name: 'meeting_group')]
#[UniqueEntity(fields: ['name'], message: 'Ya existe un grupo de convocatoria con ese nombre.')]
class MeetingGroup implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Ponle un nombre al grupo.')]
    #[Assert\Length(max: 120)]
    private string $name = '';

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'meeting_group_member')]
    private Collection $members;

    /** Retired groups stay for the audit trail but are no longer offered when convening. */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct()
    {
        $this->members = new ArrayCollection();
    }

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

    /**
     * @return Collection<int, User>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(User $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
        }

        return $this;
    }

    public function removeMember(User $member): static
    {
        $this->members->removeElement($member);

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
