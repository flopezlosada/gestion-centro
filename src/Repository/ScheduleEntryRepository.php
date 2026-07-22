<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduleEntry>
 */
class ScheduleEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduleEntry::class);
    }

    /**
     * The non-teaching duty slots (guardia and collaborator) on a weekday at a given period of a
     * course — the pool the assignment engine picks from. Teachers are eager-loaded so the panel can
     * read their name and department without extra queries.
     *
     * @param AcademicYear $year      the course whose timetable to read
     * @param Weekday      $weekday   the weekday
     * @param int          $slotIndex the period index within the day
     *
     * @return ScheduleEntry[] the guardia and collaborator entries at that period, teachers joined
     */
    public function dutyPoolAt(AcademicYear $year, Weekday $weekday, int $slotIndex): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('t')
            ->join('s.teacher', 't')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.weekday = :weekday')
            ->andWhere('s.slotIndex = :slot')
            ->andWhere('s.kind IN (:kinds)')
            ->setParameter('year', $year)
            ->setParameter('weekday', $weekday)
            ->setParameter('slot', $slotIndex)
            ->setParameter('kinds', [ScheduleActivityKind::GUARDIA, ScheduleActivityKind::COLLABORATOR])
            ->orderBy('t.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The teaching cell a teacher has on a weekday at a given period of a course, or null if they are
     * free then. Used to snapshot the group and room an absence leaves uncovered.
     *
     * @param AcademicYear $year      the course whose timetable to read
     * @param User         $teacher   the (absent) teacher
     * @param Weekday      $weekday   the weekday
     * @param int          $slotIndex the period index within the day
     *
     * @return ScheduleEntry|null the lective entry, or null when the teacher has no class then
     */
    public function lectiveAt(AcademicYear $year, User $teacher, Weekday $weekday, int $slotIndex): ?ScheduleEntry
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.teacher = :teacher')
            ->andWhere('s.weekday = :weekday')
            ->andWhere('s.slotIndex = :slot')
            ->andWhere('s.kind = :lective')
            ->setParameter('year', $year)
            ->setParameter('teacher', $teacher)
            ->setParameter('weekday', $weekday)
            ->setParameter('slot', $slotIndex)
            ->setParameter('lective', ScheduleActivityKind::LECTIVE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The distinct time slots present in a course's imported timetable, ordered by start time — the
     * periods the "Parte de guardias" screen offers as tabs. Each row is {@code [index, startsAt, endsAt]}.
     *
     * @param AcademicYear $year the course whose timetable to read
     *
     * @return list<array{index: int, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> the periods, earliest first
     */
    public function distinctSlots(AcademicYear $year): array
    {
        /** @var list<array{slotIndex: int, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.slotIndex AS slotIndex', 'MIN(s.startsAt) AS startsAt', 'MIN(s.endsAt) AS endsAt')
            ->andWhere('s.academicYear = :year')
            ->setParameter('year', $year)
            ->groupBy('s.slotIndex')
            ->orderBy('startsAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $r): array => [
                'index' => (int) $r['slotIndex'],
                'startsAt' => $r['startsAt'],
                'endsAt' => $r['endsAt'],
            ],
            $rows,
        );
    }

    /**
     * Replaces the given teachers' timetable for one course with the supplied entries. Used by the
     * importer: it wipes only the reconciled teachers' rows in that course (so unmatched teachers, and
     * every other course, keep whatever they had) and inserts the fresh ones, making a re-import of the
     * same course idempotent. The delete and the inserts run in one transaction, so a concurrent parte
     * read (now reachable any time through the self-service import screen) never sees a half-replaced
     * timetable — either the old rows or the new ones, never neither.
     *
     * @param AcademicYear        $year     the course whose entries are replaced
     * @param list<User>          $teachers the teachers whose old entries in that course are cleared
     * @param list<ScheduleEntry> $entries  the fresh entries to persist
     */
    public function replaceForTeachers(AcademicYear $year, array $teachers, array $entries): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(function () use ($em, $year, $teachers, $entries): void {
            if ([] !== $teachers) {
                $this->createQueryBuilder('s')
                    ->delete()
                    ->where('s.academicYear = :year')
                    ->andWhere('s.teacher IN (:teachers)')
                    ->setParameter('year', $year)
                    ->setParameter('teachers', $teachers)
                    ->getQuery()
                    ->execute();
            }
            foreach ($entries as $entry) {
                $em->persist($entry);
            }
            $em->flush();
        });
    }
}
