<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\User;
use App\Enum\Weekday;
use App\Repository\AbsenceRepository;
use App\Repository\GuardiaCoverRepository;
use App\Repository\ScheduleEntryRepository;
use App\Service\GuardiaAssignmentNotifier;
use App\Space\EffectiveLesson;
use App\Space\EffectiveTimetable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Registers a teacher's absence in one step and lets the equitable engine take over: given the day
 * (and either specific periods or the teacher's whole teaching day), it creates a parte line for
 * each period the teacher actually teaches — a free period or a duty needs no cover — snapshotting
 * the uncovered group and room, and then runs {@see GuardiaScheduler} for each affected period (which
 * assigns a guardia and notifies them).
 *
 * What it teaches is read off the EFFECTIVE timetable ({@see EffectiveTimetable}), not the weekly grid: an
 * approved space plan can have moved the lesson to another room (the parte has to send the covering
 * teacher to the right door) or replaced the group's timetable altogether that day (exam week), in which
 * case there is no lesson to cover and no task to leave.
 *
 * This is the single entry point behind both the coordinator's "apuntar ausencia" screen and a
 * teacher self-reporting their own absence, so the "register → auto-assign → notify" flow lives in
 * one place.
 *
 * A recreo the absence leaves unwatched is handled apart, through {@see BreakDutyGapRegistrar}: break
 * duties are not periods anybody teaches and the centre's rule is that they are NOT re-covered, so
 * instead of joining the split they are recorded and the equipo directivo is alerted.
 */
final class AbsenceRegistrar
{
    public function __construct(
        private readonly ScheduleEntryRepository $schedule,
        private readonly EffectiveTimetable $timetable,
        private readonly GuardiaCoverRepository $covers,
        private readonly AbsenceRepository $absences,
        private readonly GuardiaScheduler $scheduler,
        private readonly BreakDutyGapRegistrar $breakGaps,
        private readonly GuardiaAssignmentNotifier $notifier,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Registers the absence and runs the assignment.
     *
     * @param AcademicYear       $year        the course the date falls into (supplies the timetable)
     * @param User               $teacher     the absent teacher
     * @param \DateTimeImmutable $date        the day of the absence
     * @param list<int>|null     $slotIndexes the periods to register, or null for the whole teaching day
     * @param string|null        $reason      the private reason for the absence; only set when non-empty,
     *                                         so re-registering more periods without retyping it keeps it
     * @param array<int, array{documentPath?: ?string, documentName?: ?string, description?: ?string, copies?: ?int}> $taskBySlot
     *                                         per-period task (slot index → the group's document and/or
     *                                         description, plus the copies it needs); each period/group
     *                                         carries its own work
     * @param bool               $missesBreakDuty whether the absence also covers the recreo: told, not
     *                                         inferred, because a recreo is nobody's teaching period and
     *                                         so cannot be read off the periods ticked
     *
     * @return AbsenceRegistrationResult what was created and what was skipped — a period counts as
     *                                   skipped-free both when the timetable leaves it free and when an
     *                                   approved plan has taken the lesson away, because either way there
     *                                   is nothing to cover
     */
    public function register(AcademicYear $year, User $teacher, \DateTimeImmutable $date, ?array $slotIndexes, ?string $reason, array $taskBySlot = [], bool $missesBreakDuty = false): AbsenceRegistrationResult
    {
        $weekday = Weekday::from((int) $date->format('N'));
        // An all-day absence spans every period the teacher is booked in, guardia hours included — not
        // only the lessons. Covers are still created for lessons alone (a guardia hour has no group to
        // cover), but the absence has to know about the rest or the rota keeps handing them work.
        $slots = $slotIndexes ?? $this->schedule->bookedSlotsFor($year, $teacher, $weekday);

        // One absence per (teacher, day): reuse it if the day is already partly registered, so the
        // reason stays single-sourced.
        $absence = $this->absences->findForTeacherAndDate($teacher, $date);
        $absenceIsNew = null === $absence;
        if ($absenceIsNew) {
            $absence = (new Absence())->setAbsentTeacher($teacher)->setDate($date);
        }

        // The absence spans these periods whether or not any of them produces a cover, and it is stored
        // even when none does — that is the whole fix. A teacher away only during their guardia hours
        // generates no cover (there is no class to hand over), and under the old lazy persist the
        // absence was never written, so the rota went on treating them as available.
        //
        // The one case that still writes nothing is a day the teacher is not booked for at all: there is
        // no absence to record and an empty row would only be an orphan.
        $spanned = array_values(array_unique($slots));
        sort($spanned);
        $absence->addSlotIndexes($spanned);
        if ($absenceIsNew && [] !== $spanned) {
            $this->em->persist($absence);
        }

        $createdSlots = [];
        $skippedFree = 0;
        $skippedExisting = 0;
        foreach ($spanned as $slotIndex) {
            // A period may hold several classes at once (a multi-group activity in the assembly hall);
            // it is still ONE guardia to cover, so all its groups/rooms fold into a single cover.
            $lessons = $this->timetable->forTeacherAt($year, $teacher, $date, $slotIndex);
            if ([] === $lessons) {
                ++$skippedFree;
                continue;
            }
            if (null !== $this->covers->findOneBy(['absentTeacher' => $teacher, 'date' => $date, 'slotIndex' => $slotIndex])) {
                ++$skippedExisting;
                continue;
            }

            $task = $taskBySlot[$slotIndex] ?? [];
            $this->em->persist((new GuardiaCover())
                ->setAbsence($absence)
                ->setDate($date)
                ->setSlotIndex($slotIndex)
                ->setAbsentTeacher($teacher)
                ->setGroupName(self::snapshot(array_map(static fn (EffectiveLesson $l): ?string => $l->entry->getGroupName(), $lessons)))
                // El aula, de la rejilla efectiva: si un plan aprobado ha movido esa clase, el parte manda
                // al profe de guardia al aula NUEVA. Con el aula del horario iría a una vacía.
                ->setRoomName(self::snapshot(array_map(static fn (EffectiveLesson $l): ?string => $l->roomName(), $lessons)))
                // La materia se congela aquí porque es con lo que se casa el banco de tareas: el grupo
                // trabaja la asignatura que le tocaba, y eso no puede depender de un reimport posterior.
                ->setSubjectName(self::onlySubject($lessons))
                ->setTaskDocumentPath($task['documentPath'] ?? null)
                ->setTaskDocumentName($task['documentName'] ?? null)
                ->setTaskDescription($task['description'] ?? null)
                ->setCopiesNeeded($task['copies'] ?? null));
            $createdSlots[] = $slotIndex;
        }

        // Apply the reason only when the absence is (or already was) real: one that spans some period, or
        // an update to an already-persisted absence. Never on a brand-new absence with nothing in it.
        if ((null !== $reason && '' !== trim($reason)) && ([] !== $spanned || !$absenceIsNew)) {
            $absence->setReason($reason);
        }
        $this->em->flush();

        // Now that the absence is on record, take back the guardias this teacher was due to cover today
        // in the periods they are away for. Until this existed, a teacher who signed up as absent stayed
        // assigned to a group nobody would show up for, and nobody was told.
        $relievedSlots = $this->relieveOwnGuardias($teacher, $date, $spanned);

        // Assign each affected period after the covers exist, so the balance sees them all. The periods
        // just relieved go in too: the group still needs somebody, and now the rota cannot pick the
        // absent teacher again, because being away is a stored fact.
        $toAssign = array_values(array_unique(array_merge($createdSlots, $relievedSlots)));
        sort($toAssign);
        foreach ($toAssign as $slotIndex) {
            $this->scheduler->autoAssign($year, $date, $slotIndex);
        }

        // The recreo is a separate consequence of the same absence: it is never re-covered, only recorded
        // and alerted. It stands on its own — a teacher whose day holds no lesson at all (so no cover was
        // created) still leaves their zone unwatched.
        $breakGaps = $missesBreakDuty ? $this->breakGaps->register($year, $teacher, $date) : [];

        return new AbsenceRegistrationResult($createdSlots, $skippedFree, $skippedExisting, $breakGaps, $relievedSlots);
    }

    /**
     * Takes the absent teacher off the guardias they were assigned to cover today, in the periods the
     * absence spans, and tells them it is no longer theirs.
     *
     * Only their own assignments are touched: the class they were going to cover still needs somebody,
     * so the cover line stays and goes back into the pot to be reassigned. Periods outside the absence
     * are left alone — somebody away first thing may well do their afternoon guardia.
     *
     * They are notified with {@see \App\Service\GuardiaAssignmentNotifier::notifyRelieved()}, the same
     * message a coordinator's hand-edit sends. Being relieved after reporting your own absence is not
     * obvious from the outside: the guardia was in your agenda a moment ago and now it is not. No reason
     * travels with it — the centre decided the note behind a guardia change stays with the equipo
     * directivo — and here it would say nothing anyway: the teacher is the one who reported the absence.
     *
     * @param User               $teacher the absent teacher
     * @param \DateTimeImmutable $date    the day
     * @param list<int>          $slots   the periods the absence spans
     *
     * @return list<int> the periods that lost their guardia and need reassigning
     */
    private function relieveOwnGuardias(User $teacher, \DateTimeImmutable $date, array $slots): array
    {
        if ([] === $slots) {
            return [];
        }

        $relieved = [];
        $freed = [];
        foreach ($this->covers->findAssignedTo($teacher, $date) as $cover) {
            if (!\in_array($cover->getSlotIndex(), $slots, true)) {
                continue;
            }
            $cover->setAssignedGuardia(null);
            $relieved[] = $cover;
            $freed[] = $cover->getSlotIndex();
        }

        if ([] === $relieved) {
            return [];
        }

        $this->em->flush();
        foreach ($relieved as $cover) {
            $this->notifier->notifyRelieved($cover, $teacher);
        }

        return array_values(array_unique($freed));
    }

    /**
     * The subject of a period, when there is exactly ONE. A multi-subject period (a grouped optional,
     * an activity covering several classes at once) yields null on purpose: the bank matches the
     * subject exactly, so "Matemáticas, Física" would match nothing and the covering teacher would be
     * told the bank is empty instead of being asked to pick by hand — which is what null does.
     *
     * @param list<EffectiveLesson> $lessons the period's classes
     *
     * @return string|null the single subject, or null when there is none or more than one
     */
    private static function onlySubject(array $lessons): ?string
    {
        $subjects = array_values(array_unique(array_filter(
            array_map(static fn (EffectiveLesson $l): string => trim((string) $l->entry->getSubjectName()), $lessons),
            static fn (string $s): bool => '' !== $s,
        )));

        return 1 === \count($subjects) ? $subjects[0] : null;
    }

    /**
     * Folds the group (or room) names of a period's classes into one snapshot string: distinct,
     * non-empty, ", "-separated. A single-class period yields just its name (the common case is
     * unchanged); a multi-group activity keeps every group/room instead of losing all but one. Capped
     * to fit the snapshot column, never silently dropping data mid-value.
     *
     * @param list<string|null> $values the per-class names (groups or rooms)
     *
     * @return string|null the folded snapshot, or null when there is nothing to keep
     */
    private static function snapshot(array $values): ?string
    {
        $distinct = array_values(array_unique(array_filter(
            array_map(static fn (?string $v): string => null !== $v ? trim($v) : '', $values),
            static fn (string $v): bool => '' !== $v,
        )));
        if ([] === $distinct) {
            return null;
        }

        $joined = implode(', ', $distinct);

        return mb_strlen($joined) > 255 ? mb_substr($joined, 0, 254).'…' : $joined;
    }
}
