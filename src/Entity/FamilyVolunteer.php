<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Contract\AuditRedacted;
use App\Repository\FamilyVolunteerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A member of a family who volunteers in the centre's activities (interactive groups, workshops…) and so
 * may sign in to the families' area.
 *
 * Deliberately NOT a {@see User}: it is authenticated by a firewall of its own (security.yaml) whose
 * provider loads only this class, so a family can never hold a session in the staff area — by type, not
 * by every staff screen remembering to check. There is no "inactive" state either: removing the account
 * is how access is withdrawn, and it takes the personal data with it.
 *
 * Name and e-mail are kept out of the activity trail ({@see AuditRedacted}): the trail records that an
 * account was added or removed, but once it is removed the data must be gone, not copied into every
 * audit row and backup.
 */
#[ORM\Entity(repositoryClass: FamilyVolunteerRepository::class)]
#[ORM\Table(name: 'family_volunteer')]
#[UniqueEntity(fields: ['email'], message: 'Ya hay una familia con ese correo.')]
class FamilyVolunteer implements UserInterface, Auditable
{
    /** The one role of a family account; the families' area is gated on it. */
    public const ROLE = 'ROLE_FAMILY';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'full_name', length: 180)]
    #[Assert\NotBlank(message: 'El nombre es obligatorio.')]
    #[Assert\Length(max: 180)]
    #[AuditRedacted]
    private string $fullName = '';

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(message: 'El correo es obligatorio.')]
    #[Assert\Email(message: 'Introduce un correo electrónico válido.')]
    #[Assert\Length(max: 180)]
    #[AuditRedacted]
    private string $email = '';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * @return int|null the id, null until persisted
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string the person's name, as the centre knows them
     */
    public function getFullName(): string
    {
        return $this->fullName;
    }

    /**
     * @param string $fullName the person's name
     */
    public function setFullName(string $fullName): static
    {
        $this->fullName = trim($fullName);

        return $this;
    }

    /**
     * @return string the address they sign in with
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Stores the address normalised, so the sign-in lookup and the unique index agree.
     *
     * @param string $email the address they sign in with
     */
    public function setEmail(string $email): static
    {
        $this->email = strtolower(trim($email));

        return $this;
    }

    /**
     * @return \DateTimeImmutable when the account was created
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Unique identifier used by the security system (the e-mail address).
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * Only {@see ROLE}: never ROLE_STAFF, which is what the rest of the app requires.
     *
     * @return string[]
     */
    public function getRoles(): array
    {
        return [self::ROLE];
    }

    /**
     * No-op: sign-in is passwordless (e-mail link), so there are no credentials to erase.
     */
    public function eraseCredentials(): void
    {
    }
}
