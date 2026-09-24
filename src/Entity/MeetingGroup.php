<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Enum\EventReminderOffset;
use App\Enum\Weekday;
use App\Repository\MeetingGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
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
 * A group can also REPEAT WEEKLY: the standing meetings of the timetable (tutores de 3º ESO on Monday
 * at 7th period, each department, the CCP) come from Peñalara with their day, period and members
 * ({@see \App\Service\MeetingGroupImporter}), and once the group has somebody to convene it, the daily
 * sweep creates each week's meeting on its own ({@see \App\Service\RecurringMeetingGenerator}). The
 * generated meetings point back here ({@see Meeting::getMeetingGroup()}), which is what lets a meeting
 * find "the previous one" whose acta it approves. Still a prefill, though: each generated meeting copies
 * the members at the moment it is created, and editing the group never rewrites one already generated.
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
    #[ORM\OrderBy(['fullName' => 'ASC'])]
    private Collection $members;

    /** Retired groups stay for the audit trail but are no longer offered when convening. */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /**
     * Day of the weekly repetition. Set together with {@see $slotIndex} or not at all — only through
     * {@see repeatWeekly()} / {@see stopRepeating()}, so "a day with no period" cannot exist.
     */
    #[ORM\Column(name: 'weekday', type: Types::SMALLINT, nullable: true, enumType: Weekday::class)]
    private ?Weekday $weekday = null;

    /** Period of the day ({@see TimeSlot::$slotIndex}) of the weekly repetition. */
    #[ORM\Column(name: 'slot_index', type: Types::SMALLINT, nullable: true)]
    private ?int $slotIndex = null;

    /**
     * Who convenes the generated meetings (and so keeps their acta). Peñalara does not say it, so it is
     * set here by hand; without it nothing is generated, because a {@see Meeting} cannot exist without
     * a convener.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'convener_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $convener = null;

    /** Kind given to the generated meetings, which also decides whether their acta needs approval. */
    #[ORM\ManyToOne(targetEntity: MeetingType::class)]
    #[ORM\JoinColumn(name: 'meeting_type_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?MeetingType $type = null;

    /**
     * Where the weekly meeting is held, copied to each generated meeting. Here and not left for the
     * convener to fill in every week: it rarely changes, and filling it in on an already-generated
     * meeting would read as a change of place.
     */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $place = null;

    /** How long before each generated meeting the convened get a push reminder, or null for none. */
    #[ORM\Column(name: 'reminder_minutes', type: Types::INTEGER, nullable: true, enumType: EventReminderOffset::class)]
    private ?EventReminderOffset $reminder = null;

    /**
     * The meeting's name in Peñalara, the key a re-import finds this group by. Kept apart from
     * {@see $name} so renaming the group here does not make the next import create a duplicate.
     */
    #[ORM\Column(name: 'penalara_key', length: 120, unique: true, nullable: true)]
    private ?string $penalaraKey = null;

    /**
     * Whether the day, period or members were changed here after the last import. A re-import that
     * would overwrite them asks first ({@see \App\Service\MeetingGroupImporter}).
     */
    #[ORM\Column(name: 'edited_since_import', options: ['default' => false])]
    private bool $editedSinceImport = false;

    /**
     * Last day the weekly meetings have been generated for. The sweep only ever generates AFTER it, so
     * an occurrence the convener deleted (a holiday, a cancelled week) is never brought back.
     */
    #[ORM\Column(name: 'generated_through', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $generatedThrough = null;

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

    /**
     * Makes the group repeat every week on the given day and period.
     *
     * @param Weekday $weekday   the day of the week
     * @param int     $slotIndex the period of the day
     */
    public function repeatWeekly(Weekday $weekday, int $slotIndex): static
    {
        // Un día u hora nuevos invalidan la marca de generación: los días ya repasados con el horario viejo
        // se saltaron por no ser el día del grupo, y con el nuevo alguno sí lo es. Sin esto, pasar de jueves
        // a martes perdía el martes que ya caía dentro de la semana generada. El generador no duplica: una
        // reunión del grupo a esa misma hora ya existente no se vuelve a crear.
        if ($weekday !== $this->weekday || $slotIndex !== $this->slotIndex) {
            $this->generatedThrough = null;
        }
        $this->weekday = $weekday;
        $this->slotIndex = $slotIndex;

        return $this;
    }

    /**
     * Stops the weekly repetition. The meetings already generated and not yet held are cancelled when the
     * edit is saved ({@see \App\Service\RecurringMeetingGenerator::applySeriesChange()}); past ones stay.
     */
    public function stopRepeating(): static
    {
        $this->weekday = null;
        $this->slotIndex = null;

        return $this;
    }

    public function getWeekday(): ?Weekday
    {
        return $this->weekday;
    }

    public function getSlotIndex(): ?int
    {
        return $this->slotIndex;
    }

    /**
     * Whether the group repeats weekly (it has a day and a period).
     */
    public function repeatsWeekly(): bool
    {
        return null !== $this->weekday && null !== $this->slotIndex;
    }

    /**
     * Whether the daily sweep generates meetings for it: active, repeating and with somebody to convene.
     */
    public function generatesMeetings(): bool
    {
        return $this->active && $this->repeatsWeekly() && null !== $this->convener;
    }

    public function getConvener(): ?User
    {
        return $this->convener;
    }

    public function setConvener(?User $convener): static
    {
        $this->convener = $convener;

        return $this;
    }

    public function getPlace(): ?string
    {
        return $this->place;
    }

    public function setPlace(?string $place): static
    {
        $this->place = $place;

        return $this;
    }

    public function getReminder(): ?EventReminderOffset
    {
        return $this->reminder;
    }

    public function setReminder(?EventReminderOffset $reminder): static
    {
        $this->reminder = $reminder;

        return $this;
    }

    public function getType(): ?MeetingType
    {
        return $this->type;
    }

    public function setType(?MeetingType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getPenalaraKey(): ?string
    {
        return $this->penalaraKey;
    }

    public function setPenalaraKey(?string $penalaraKey): static
    {
        $this->penalaraKey = null !== $penalaraKey ? trim($penalaraKey) : null;

        return $this;
    }

    /**
     * Everything its generated meetings copy from it, taken before an edit so the change can be carried
     * to the meetings already created ({@see \App\Service\RecurringMeetingGenerator::applySeriesChange()}).
     *
     * @return array{name: string, type: MeetingType|null, convener: User|null, place: string|null, reminder: EventReminderOffset|null, weekday: Weekday|null, slot: int|null, members: list<User>} the shape
     */
    public function seriesShape(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'convener' => $this->convener,
            'place' => $this->place,
            'reminder' => $this->reminder,
            'weekday' => $this->weekday,
            'slot' => $this->slotIndex,
            'members' => array_values($this->members->toArray()),
        ];
    }

    /**
     * What an import owns — the day, the period and the members — as a comparable value: two groups with
     * the same shape would be left alone by an import.
     *
     * @return array{weekday: int|null, slot: int|null, members: list<int>} the shape, members as sorted ids
     */
    public function importedShape(): array
    {
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $this->members->toArray());
        sort($ids);

        return ['weekday' => $this->weekday?->value, 'slot' => $this->slotIndex, 'members' => $ids];
    }

    public function isEditedSinceImport(): bool
    {
        return $this->editedSinceImport;
    }

    /**
     * Records that the day, period or members were changed by hand.
     */
    public function markEditedSinceImport(): static
    {
        $this->editedSinceImport = true;

        return $this;
    }

    /**
     * Records that an import just wrote the day, period and members.
     */
    public function markImported(): static
    {
        $this->editedSinceImport = false;

        return $this;
    }

    public function getGeneratedThrough(): ?\DateTimeImmutable
    {
        return $this->generatedThrough;
    }

    /**
     * Moves the generation mark forward; never back, so a deleted occurrence is never regenerated.
     *
     * @param \DateTimeImmutable $day the last day now generated
     */
    public function markGeneratedThrough(\DateTimeImmutable $day): static
    {
        $day = $day->setTime(0, 0);
        if (null === $this->generatedThrough || $day > $this->generatedThrough) {
            $this->generatedThrough = $day;
        }

        return $this;
    }
}
