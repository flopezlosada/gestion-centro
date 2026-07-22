<?php

declare(strict_types=1);

namespace App\Repository;

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
     * The non-teaching duty slots (guardia and collaborator) on a weekday at a given period — the pool
     * the assignment engine picks from. Teachers are eager-loaded so the panel can read their name and
     * department without extra queries.
     *
     * @param Weekday $weekday   the weekday
     * @param int     $slotIndex the period index within the day
     *
     * @return ScheduleEntry[] the guardia and collaborator entries at that period, teachers joined
     */
    public function dutyPoolAt(Weekday $weekday, int $slotIndex): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('t')
            ->join('s.teacher', 't')
            ->andWhere('s.weekday = :weekday')
            ->andWhere('s.slotIndex = :slot')
            ->andWhere('s.kind IN (:kinds)')
            ->setParameter('weekday', $weekday)
            ->setParameter('slot', $slotIndex)
            ->setParameter('kinds', [ScheduleActivityKind::GUARDIA, ScheduleActivityKind::COLLABORATOR])
            ->orderBy('t.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The teaching cell a teacher has on a weekday at a given period, or null if they are free then.
     * Used to snapshot the group and room an absence leaves uncovered.
     *
     * @param User    $teacher   the (absent) teacher
     * @param Weekday $weekday   the weekday
     * @param int     $slotIndex the period index within the day
     *
     * @return ScheduleEntry|null the lective entry, or null when the teacher has no class then
     */
    public function lectiveAt(User $teacher, Weekday $weekday, int $slotIndex): ?ScheduleEntry
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.teacher = :teacher')
            ->andWhere('s.weekday = :weekday')
            ->andWhere('s.slotIndex = :slot')
            ->andWhere('s.kind = :lective')
            ->setParameter('teacher', $teacher)
            ->setParameter('weekday', $weekday)
            ->setParameter('slot', $slotIndex)
            ->setParameter('lective', ScheduleActivityKind::LECTIVE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The distinct time slots present in the imported timetable, ordered by start time — the periods
     * the "Parte de guardias" screen offers as tabs. Each row is {@code [index, startsAt, endsAt]}.
     *
     * @return list<array{index: int, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> the periods, earliest first
     */
    public function distinctSlots(): array
    {
        /** @var list<array{slotIndex: int, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.slotIndex AS slotIndex', 'MIN(s.startsAt) AS startsAt', 'MIN(s.endsAt) AS endsAt')
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
     * Replaces the whole timetable of the given teachers with the supplied entries, in one flush.
     * Used by the importer: it wipes only the reconciled teachers' rows (so unmatched teachers keep
     * whatever they had) and inserts the fresh ones, making a re-import idempotent.
     *
     * @param list<User>          $teachers the teachers whose old entries are cleared
     * @param list<ScheduleEntry> $entries  the fresh entries to persist
     */
    public function replaceForTeachers(array $teachers, array $entries): void
    {
        $em = $this->getEntityManager();
        if ([] !== $teachers) {
            $this->createQueryBuilder('s')
                ->delete()
                ->where('s.teacher IN (:teachers)')
                ->setParameter('teachers', $teachers)
                ->getQuery()
                ->execute();
        }
        foreach ($entries as $entry) {
            $em->persist($entry);
        }
        $em->flush();
    }
}
