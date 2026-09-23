<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AcademicYear;
use App\Entity\ScheduleEntry;
use App\Entity\TimeSlot;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\TimeSlotKind;
use App\Enum\Weekday;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The 2025-2026 course with the centre's REAL marco horario — eight indexes, the recreos at 3 and 6, so
 * the sixth class is index 7 — and a way to give a teacher a class in it. Shared by the tests of the
 * teacher's own classes (the service, the calendar and the home), which all need the same day to count.
 */
trait BuildsTheCentresTimetable
{
    /**
     * Persists the 2025-2026 course and its marco horario (not flushed).
     *
     * @param EntityManagerInterface $em the entity manager
     *
     * @return AcademicYear the course
     */
    private function courseWithTheCentresFrame(EntityManagerInterface $em): AcademicYear
    {
        $year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-23'));
        $em->persist($year);

        foreach ([
            [0, '08:25', '09:20', TimeSlotKind::LECTIVE],
            [1, '09:20', '10:15', TimeSlotKind::LECTIVE],
            [2, '10:15', '11:10', TimeSlotKind::LECTIVE],
            [3, '11:10', '11:35', TimeSlotKind::BREAK_TIME],
            [4, '11:35', '12:30', TimeSlotKind::LECTIVE],
            [5, '12:30', '13:25', TimeSlotKind::LECTIVE],
            [6, '13:25', '13:35', TimeSlotKind::BREAK_TIME],
            [7, '13:35', '14:30', TimeSlotKind::LECTIVE],
        ] as [$index, $from, $to, $kind]) {
            $em->persist((new TimeSlot())->setAcademicYear($year)->setSlotIndex($index)
                ->setStartsAt(new \DateTimeImmutable($from))->setEndsAt(new \DateTimeImmutable($to))->setKind($kind));
        }

        return $year;
    }

    /**
     * Persists one lective timetable cell (not flushed).
     *
     * @param EntityManagerInterface $em        the entity manager
     * @param AcademicYear           $year      the course
     * @param User                   $teacher   who teaches it
     * @param Weekday                $weekday   the day of the week
     * @param int                    $slotIndex the period index
     * @param string                 $group     the group, as Peñalara names it
     * @param string                 $room      the room
     * @param string                 $subject   the subject
     */
    private function classCell(EntityManagerInterface $em, AcademicYear $year, User $teacher, Weekday $weekday, int $slotIndex, string $group, string $room, string $subject = 'Literatura Universal'): void
    {
        $em->persist((new ScheduleEntry())
            ->setAcademicYear($year)->setTeacher($teacher)
            ->setWeekday($weekday)->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:00'))->setEndsAt(new \DateTimeImmutable('09:00'))
            ->setKind(ScheduleActivityKind::LECTIVE)
            ->setGroupName($group)->setRoomName($room)->setSubjectName($subject));
    }
}
