<?php

declare(strict_types=1);

namespace App\Penalara;

use App\Enum\ScheduleActivityKind;

/**
 * One parsed cell of the Peñalara resolved timetable, before it is matched to a {@see \App\Entity\User}
 * and persisted as a {@see \App\Entity\ScheduleEntry}.
 *
 * Immutable value object with no framework or database dependency, so the parser can be unit-tested
 * in isolation. Names are already resolved from the planificador dictionary (human-readable), while
 * {@see $teacherCode} keeps the raw Peñalara employee code for reconciliation.
 */
final class ScheduleEntryDto
{
    /**
     * @param string                $teacherCode  the Peñalara employee code (X_EMPLEADO)
     * @param string                $teacherName  the teacher's full name ("Apellidos, Nombre")
     * @param int                   $weekday      ISO weekday, 1 (Monday) … 7 (Sunday)
     * @param int                   $slotIndex    the period's 0-based index within the day
     * @param string                $startsAt     slot start as "HH:MM:SS"
     * @param string                $endsAt       slot end as "HH:MM:SS"
     * @param ScheduleActivityKind  $kind         teaching, guardia or collaborator
     * @param string|null           $groupName    group short name (lective only)
     * @param string|null           $roomName     room short name (lective only)
     * @param string|null           $subjectName  subject short name (lective only)
     */
    public function __construct(
        public readonly string $teacherCode,
        public readonly string $teacherName,
        public readonly int $weekday,
        public readonly int $slotIndex,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly ScheduleActivityKind $kind,
        public readonly ?string $groupName = null,
        public readonly ?string $roomName = null,
        public readonly ?string $subjectName = null,
    ) {
    }

    /**
     * A stable key for exact-duplicate detection (the resolved timetable can list the same cell more
     * than once, e.g. identical guardia rows).
     *
     * @return string the deduplication key
     */
    public function dedupeKey(): string
    {
        return implode('|', [
            $this->teacherCode,
            $this->weekday,
            $this->slotIndex,
            $this->kind->value,
            $this->groupName ?? '',
            $this->roomName ?? '',
            $this->subjectName ?? '',
        ]);
    }
}
