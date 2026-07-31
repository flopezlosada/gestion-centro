<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Enum\NotificationChannel;
use App\Enum\NotificationTopic;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A person who uses the system. Holds one or more {@see Role}s (a person can be responsible for
 * several areas, and an area can have several co-responsibles).
 *
 * Passwordless: authentication is by magic link / SSO, so no credentials are stored. The role
 * collection is exposed as {@see getAssignedRoles()} on purpose: the name getRoles() is reserved
 * for Symfony's UserInterface contract (which returns string[]), to avoid a signature clash.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[UniqueEntity(fields: ['email'], message: 'Ya existe un usuario con ese correo.')]
class User implements UserInterface, Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $fullName;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email;

    #[ORM\Column]
    private bool $active = true;

    /**
     * Whether this person may sign in while sign-in is restricted to a reduced group (see
     * {@see \App\Service\AppSettings::isLoginOpen()}). Ignored while sign-in is open, so it is the
     * roll-out list, not a second allow-list: it never takes access away from anybody.
     */
    #[ORM\Column(name: 'early_access', options: ['default' => false])]
    private bool $earlyAccess = false;

    /**
     * When this person's access was last changed (activated/deactivated, early access granted or
     * withdrawn). Compared against the session's creation time to expel someone whose access was
     * revoked while they were already inside; see
     * {@see \App\EventSubscriber\AccessRevocationSubscriber}. Null until the first change.
     */
    #[ORM\Column(name: 'access_changed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $accessChangedAt = null;

    /**
     * The teacher's stable code in Peñalara GHC (the resolved timetable's {@code X_EMPLEADO}). Set
     * once during timetable reconciliation and then used to re-link the imported schedule on every
     * later import without re-matching by name. Nullable because non-teaching users (or teachers not
     * yet reconciled) have none; unique so a Peñalara teacher maps to exactly one person.
     */
    #[ORM\Column(name: 'penalara_code', length: 32, unique: true, nullable: true)]
    #[Assert\Length(max: 32)]
    private ?string $penalaraCode = null;

    /** @var Collection<int, Role> */
    #[ORM\ManyToMany(targetEntity: Role::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'user_role')]
    private Collection $assignedRoles;

    /**
     * The unit (department, office…) this person belongs to, used to walk the chain of command for
     * escalation and validation. Nullable while the org chart is incomplete.
     */
    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'unit_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Department $unit = null;

    /**
     * How this person wants to be notified, per section: {@see NotificationTopic}'s value → a
     * {@see NotificationChannel}'s value. A topic ABSENT from the map means "no lo he elegido", which
     * is not the same as any of the three channels — the app then applies its own default for that kind
     * of notice ({@see \App\Service\NotificationDispatcher::channelFor()}).
     *
     * A JSON column and not a table of its own: it is a handful of scalars belonging to one person,
     * always read together with them and never queried across people ("¿quién quiere correo?" is not a
     * question anyone asks). A join table would be five rows per user to answer nothing extra.
     *
     * Kept private and only reachable through {@see channelFor()} / {@see setChannelFor()}, which speak
     * enums: no caller can write a topic or a channel that does not exist.
     *
     * @var array<string, string>
     */
    #[ORM\Column(name: 'notification_channels', type: Types::JSON)]
    private array $notificationChannels = [];

    public function __construct()
    {
        $this->assignedRoles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        // Store normalised so lookups and the unique index are consistent.
        $this->email = strtolower(trim($email));

        return $this;
    }

    public function getUnit(): ?Department
    {
        return $this->unit;
    }

    public function setUnit(?Department $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getPenalaraCode(): ?string
    {
        return $this->penalaraCode;
    }

    public function setPenalaraCode(?string $penalaraCode): static
    {
        $this->penalaraCode = null !== $penalaraCode ? trim($penalaraCode) : null;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->stampAccessChange($active !== $this->active);
        $this->active = $active;

        return $this;
    }

    public function hasEarlyAccess(): bool
    {
        return $this->earlyAccess;
    }

    public function setEarlyAccess(bool $earlyAccess): static
    {
        $this->stampAccessChange($earlyAccess !== $this->earlyAccess);
        $this->earlyAccess = $earlyAccess;

        return $this;
    }

    public function getAccessChangedAt(): ?\DateTimeImmutable
    {
        return $this->accessChangedAt;
    }

    /**
     * Records that the person's access was just changed, so any session opened before this moment
     * can be re-checked and dropped. Kept inside the setters rather than left to each caller: the
     * timestamp is an invariant of "access changed", and a caller that forgot to set it would leave
     * a revoked user quietly browsing on.
     *
     * @param bool $changed whether the setter actually changed the value (a no-op write must not
     *                      invalidate anyone's session)
     */
    private function stampAccessChange(bool $changed): void
    {
        if ($changed) {
            $this->accessChangedAt = new \DateTimeImmutable();
        }
    }

    /**
     * The responsibilities held by this user. Named to avoid clashing with
     * {@see \Symfony\Component\Security\Core\User\UserInterface::getRoles()}.
     *
     * @return Collection<int, Role>
     */
    public function getAssignedRoles(): Collection
    {
        return $this->assignedRoles;
    }

    public function addAssignedRole(Role $role): static
    {
        if (!$this->assignedRoles->contains($role)) {
            $this->assignedRoles->add($role);
            $role->linkHolder($this);
        }

        return $this;
    }

    public function removeAssignedRole(Role $role): static
    {
        if ($this->assignedRoles->removeElement($role)) {
            $role->unlinkHolder($this);
        }

        return $this;
    }

    /**
     * Whether this user holds the given responsibility. Single definition of "mine", shared by the
     * dashboard worklist and the "Qué toca" scope filter. Compares by persisted id, so an unpersisted
     * role is never considered held.
     *
     * @param Role $role the responsibility to check
     *
     * @return bool true if the user has this role assigned
     */
    public function holdsRole(Role $role): bool
    {
        $id = $role->getId();
        if (null === $id) {
            return false;
        }

        return $this->assignedRoles->exists(static fn (int $key, Role $held): bool => $held->getId() === $id);
    }

    /**
     * Whether the user holds a role with the given code (e.g. 'direction'). Used for type-based
     * document approval, where the approving role is identified by its code.
     *
     * @param string $code the role code to look for
     *
     * @return bool true if the user has a role with that code
     */
    public function holdsRoleCode(string $code): bool
    {
        return $this->assignedRoles->exists(static fn (int $key, Role $held): bool => $held->getCode() === $code);
    }

    /**
     * Unique identifier used by the security system (the e-mail address).
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * Security roles derived from the assigned responsibilities. Every authenticated user has
     * ROLE_USER; holding any role flagged as admin (see {@see Role::isAdmin()}) adds ROLE_ADMIN,
     * which gates the sensitive /audit trail and bypasses the per-area matrix in
     * {@see \App\Security\Voter\AreaVoter} (so it also opens the /admin back-office, gated by write
     * access to {@see \App\Enum\Area::ADMINISTRATION}). Admin power is therefore an explicit flag,
     * not a side effect of a role's code.
     *
     * @return string[]
     */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        foreach ($this->assignedRoles as $role) {
            if ($role->isAdmin()) {
                $roles[] = 'ROLE_ADMIN';
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * The channel this person chose for a section, or null if they never chose one.
     *
     * Null is a real answer and not a default in disguise: "no lo he tocado" lets the app keep its own
     * policy per kind of notice (an agenda nudge that fires ten minutes before does not belong in an
     * inbox), whereas any of the three channels is an explicit instruction that overrides it.
     *
     * @param NotificationTopic $topic the section
     *
     * @return NotificationChannel|null the chosen channel, or null when unset
     */
    public function channelFor(NotificationTopic $topic): ?NotificationChannel
    {
        $stored = $this->notificationChannels[$topic->value] ?? null;

        // tryFrom and not from(): a value written by an older version of the app (or by hand) must not
        // blow up every notice for that person — it just reads as "sin elegir".
        return \is_string($stored) ? NotificationChannel::tryFrom($stored) : null;
    }

    /**
     * Sets (or clears, with null) the channel for a section.
     *
     * @param NotificationTopic        $topic   the section
     * @param NotificationChannel|null $channel the channel, or null to go back to the app's default
     */
    public function setChannelFor(NotificationTopic $topic, ?NotificationChannel $channel): static
    {
        if (null === $channel) {
            unset($this->notificationChannels[$topic->value]);
        } else {
            $this->notificationChannels[$topic->value] = $channel->value;
        }

        return $this;
    }

    /**
     * Whether this person has ever chosen how they want to be notified. Drives the prompt on the way in:
     * it is asked once and never again, instead of nagging everybody forever.
     *
     * @return bool true once any section has an explicit channel
     */
    public function hasChosenNotificationChannels(): bool
    {
        return [] !== $this->notificationChannels;
    }

    /**
     * No-op: this is a passwordless system (magic link / SSO), so there are no sensitive
     * credentials to erase.
     */
    public function eraseCredentials(): void
    {
    }
}
