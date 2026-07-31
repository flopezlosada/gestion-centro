<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Who a meeting is with: students, families, or the staff itself. The centre asked to state it, and it
 * is not decoration — it decides whether the meeting has minutes AT ALL:
 *
 *   "Cuando hay una reunión tiene que aparecer cuadros de diálogos con orden del día, desarrollo de la
 *    reunión y acuerdos. Esta información solo aparece si la reunión es con equipo docente. Las reuniones
 *    con alumnado y familias se registran en RAICES."
 *
 * So a meeting with a family is recorded here as an appointment — it goes in the agenda, it reminds you,
 * it says who is convened — and nothing else: what was said goes into RAICES, and duplicating it would
 * mean two half-registers, each missing what the other has.
 *
 * This is an enum in code and NOT a table the centre edits, unlike {@see \App\Entity\MeetingType}. The
 * difference is that these three change BEHAVIOUR: adding a fourth from a settings screen would create
 * meetings the application does not know whether to ask an acta for. The kinds of staff meeting — CCP,
 * tutores, ED, AMPA, comisiones… — are the ones that grow, and those are a table precisely because they
 * only change the label.
 */
enum MeetingScope: string
{
    case STAFF = 'staff';
    case STUDENTS = 'students';
    case FAMILIES = 'families';

    /**
     * The label shown when convening and on the meeting.
     *
     * @return string the Spanish label
     */
    public function label(): string
    {
        return match ($this) {
            self::STAFF => 'Con equipo docente',
            self::STUDENTS => 'Con alumnado',
            self::FAMILIES => 'Con familias',
        };
    }

    /**
     * Whether this meeting keeps minutes in the application (agenda, what was discussed, agreements).
     *
     * @return bool true only for staff meetings
     */
    public function keepsMinutes(): bool
    {
        return self::STAFF === $this;
    }

    /**
     * What to tell whoever convenes it, so the missing acta does not read as a bug.
     *
     * @return string the Spanish note, empty for staff meetings
     */
    public function note(): string
    {
        return match ($this) {
            self::STAFF => '',
            self::STUDENTS => 'Las reuniones con alumnado se registran en RAICES: aquí solo queda la cita y a quién se convoca.',
            self::FAMILIES => 'Las reuniones con familias se registran en RAICES: aquí solo queda la cita y a quién se convoca.',
        };
    }
}
