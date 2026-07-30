<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\GuardiaSupportRepository;
use App\Util\Excerpt;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A colleague signed up BY HAND to be available for guardias on one day and period, because they
 * happen to be free that hour even though their timetable says otherwise — the centre's case is a
 * teacher whose 2º de Bachillerato or CFGB group has finished lessons for the year.
 *
 * Deliberately keyed by DATE, not by weekday: the weekly rota already has its place
 * ({@see ScheduleEntry} and the "horario de guardias" editor), and hanging a date off that table
 * would blur two different things — a duty that repeats every week and a favour done on a Tuesday.
 * That is also why an import can never touch these.
 *
 * The assignment engine treats them as the last band before doubling somebody up (see
 * {@see \App\Enum\GuardiaDutyBand}): after the rota and the collaborators, because being free is not
 * the same as being on call. Their covers count towards the equitable balance like anyone else's.
 *
 * The timetable is NOT consulted to validate this: the whole point is that it says the teacher is
 * teaching and reality says they are not. The screen warns about the clash and the person decides —
 * see {@see \App\Controller\GuardiaDeficitController}.
 *
 * Auditable: it is a hand gesture with consequences for somebody else's afternoon, so who added it
 * and when belongs in the activity trail.
 */
#[ORM\Entity(repositoryClass: GuardiaSupportRepository::class)]
#[ORM\Table(name: 'guardia_support')]
#[ORM\Index(name: 'IDX_support_date_slot', columns: ['support_date', 'slot_index'])]
#[ORM\UniqueConstraint(name: 'UNIQ_guardia_support', columns: ['teacher_id', 'support_date', 'slot_index'])]
class GuardiaSupport implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The colleague made available. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $teacher;

    /** The single day they are available; this is never a recurring arrangement. */
    #[ORM\Column(name: 'support_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** The period within the day (0-based Peñalara {@code indice}), matching {@see ScheduleEntry::$slotIndex}. */
    #[ORM\Column(name: 'slot_index', type: Types::SMALLINT)]
    private int $slotIndex;

    /**
     * Why they are free ("2º de Bach ha terminado las clases"). Optional, but the parte shows it: the
     * next coordinator to open the screen has no other way of telling a deliberate arrangement from a
     * mistake.
     */
    #[ORM\Column(name: 'note', length: 255, nullable: true)]
    private ?string $note = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeacher(): User
    {
        return $this->teacher;
    }

    public function setTeacher(User $teacher): static
    {
        $this->teacher = $teacher;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getSlotIndex(): int
    {
        return $this->slotIndex;
    }

    public function setSlotIndex(int $slotIndex): static
    {
        $this->slotIndex = $slotIndex;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    /**
     * Sets the note, normalising blank to null and clamping it to what the column holds. Clamped here
     * rather than trusted from the form: a value over 255 characters would otherwise surface as a 500
     * instead of as a saved note.
     *
     * @param string|null $note why the teacher is free, or null/blank for none
     */
    public function setNote(?string $note): static
    {
        $clamped = Excerpt::of($note, 255);
        $this->note = '' !== $clamped ? $clamped : null;

        return $this;
    }
}
