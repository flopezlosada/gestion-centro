<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Meeting;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\EventReminderOffset;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form-backing object for a {@see Meeting}. A DTO on purpose, for the same reason as
 * {@see PersonalEventFormData}: the entity stores instants, but a convocatoria is written as a day plus
 * "from"/"until" times, which the controller composes back ({@see \App\Util\CalendarDate::at()}).
 *
 * The convener is never in the form — it is whoever is logged in.
 */
final class MeetingFormData
{
    #[Assert\NotBlank(message: 'Ponle un título a la reunión.')]
    #[Assert\Length(max: 200)]
    public string $title = '';

    /** The agenda ("orden del día"). */
    public ?string $agenda = null;

    #[Assert\Length(max: 120)]
    public ?string $place = null;

    #[Assert\NotNull(message: 'Pon el día de la reunión.')]
    public ?\DateTimeImmutable $day = null;

    /**
     * Start time. Required, unlike a personal event's: a convocatoria without an hour is not a
     * convocatoria — nobody knows when to turn up.
     */
    #[Assert\NotNull(message: 'Pon la hora a la que empieza.')]
    public ?\DateTimeImmutable $startTime = null;

    /** End time, or null when the meeting has no announced end. */
    public ?\DateTimeImmutable $endTime = null;

    /**
     * How long before the start everybody convened gets a push reminder, or null for none. Defaults to ten
     * minutes because the centre asked for meetings to remind: a convocatoria you agreed to in September
     * and forget in November is exactly the failure this fixes.
     */
    public ?EventReminderOffset $reminder = EventReminderOffset::TEN_MINUTES;

    /** The project this meeting belongs to, or null when it is not a project meeting. */
    public ?Project $project = null;

    /**
     * The people convened, besides the convener.
     *
     * @var list<User>
     */
    #[Assert\Count(min: 1, minMessage: 'Convoca al menos a una persona.')]
    public array $attendees = [];

    /**
     * The end must come after the start. Compares only the "HH:MM" of each, since the date part of a
     * time field is meaningless (see {@see PersonalEventFormType}).
     *
     * @param ExecutionContextInterface $context the validation context to attach violations to
     */
    #[Assert\Callback]
    public function validateTimes(ExecutionContextInterface $context): void
    {
        if (null === $this->startTime || null === $this->endTime) {
            return;
        }

        if ($this->endTime->format('H:i') <= $this->startTime->format('H:i')) {
            $context->buildViolation('La hora de fin debe ser posterior a la de inicio.')
                ->atPath('endTime')
                ->addViolation();
        }
    }

    /**
     * Prefills the form from an existing meeting (for editing), splitting its instants back into a day
     * and the times of day.
     *
     * @param Meeting $meeting the meeting to edit
     *
     * @return self the prefilled form data
     */
    public static function fromMeeting(Meeting $meeting): self
    {
        $data = new self();
        $data->title = $meeting->getTitle();
        $data->agenda = $meeting->getAgenda();
        $data->place = $meeting->getPlace();
        $data->day = $meeting->getStartAt()->setTime(0, 0);
        // The instants go in whole: the time field renders only their "HH:MM".
        $data->startTime = $meeting->getStartAt();
        $data->endTime = $meeting->getEndAt();
        // Whatever the meeting actually has, including "sin aviso" — the default only applies to a new one.
        $data->reminder = $meeting->getReminder();
        $data->project = $meeting->getProject();
        $data->attendees = array_values($meeting->getAttendees()->toArray());

        return $data;
    }
}
