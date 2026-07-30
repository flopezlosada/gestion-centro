<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A project of the centre (Erasmus+, Huerto escolar, Plan digital…): a group of teachers who work on
 * something together, with one of them coordinating it.
 *
 * A project is NOT a {@see Department} and must never be treated as one. A department is where a
 * teacher BELONGS (exactly one, {@see User::getUnit()}) and it is the scope of the chain of command
 * ({@see \App\Service\OrganizationHierarchy}); a project is a voluntary grouping a teacher may be in
 * zero, one or many of, and it grants NO rank over anybody. That is why membership lives here as a
 * many-to-many and not in the user, and why the coordinator is a field of the project rather than a
 * ranked role: coordinating project A must not make you coordinator of project B.
 *
 * The coordinator is the single source of truth for "whose project is this" — the role
 * "project_coordinator" is the catalogue label of that job (no rank, see {@see Role}), while the SCOPE
 * (which project) is this field. Used by {@see \App\Service\MeetingAccess} to decide who may convene a
 * meeting and upload its minutes.
 */
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'project')]
#[ORM\UniqueConstraint(name: 'uniq_project_name', columns: ['name'])]
#[UniqueEntity(fields: ['name'], message: 'Ya existe un proyecto con ese nombre.')]
class Project implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Ponle un nombre al proyecto.')]
    #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Finished projects are kept for history (their meetings and minutes stay readable) but no longer
     * offered when convening. Soft delete, same reasoning as {@see Department::isActive()}.
     */
    #[ORM\Column]
    private bool $active = true;

    /**
     * Who coordinates the project: the person who may convene its meetings and upload their minutes.
     * Nullable while the post is vacant (and cleared with ON DELETE SET NULL if the person is removed),
     * which simply means nobody can convene for this project until one is named.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'coordinator_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $coordinator = null;

    /**
     * The teachers who make up the project. They are the DEFAULT attendees of its meetings (see
     * {@see \App\Controller\MeetingController}), not a permission: being a member grants no rights over
     * anybody else's work.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'project_member')]
    private Collection $members;

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
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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

    public function getCoordinator(): ?User
    {
        return $this->coordinator;
    }

    public function setCoordinator(?User $coordinator): static
    {
        $this->coordinator = $coordinator;

        return $this;
    }

    /**
     * @return Collection<int, User> the teachers in the project
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

    /**
     * Whether the given person coordinates this project — the single definition of that check, shared by
     * {@see \App\Service\MeetingAccess} and the templates.
     *
     * @param User $user the person to check
     *
     * @return bool true if the user is this project's coordinator
     */
    public function isCoordinatedBy(User $user): bool
    {
        return null !== $this->coordinator && $this->coordinator === $user;
    }

    /**
     * The project's people for a meeting: its members plus its coordinator (who coordinates it without
     * necessarily being listed as a member). Deduplicated, so a coordinator who is also a member appears
     * once.
     *
     * @return list<User> the members and the coordinator
     */
    public function people(): array
    {
        $people = $this->members->toArray();
        if (null !== $this->coordinator && !\in_array($this->coordinator, $people, true)) {
            $people[] = $this->coordinator;
        }

        return array_values($people);
    }
}
