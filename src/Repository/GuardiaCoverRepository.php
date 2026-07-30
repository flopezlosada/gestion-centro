<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuardiaCover;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuardiaCover>
 */
class GuardiaCoverRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuardiaCover::class);
    }

    /**
     * How many covers on a given day are still without an assigned guardia — the "ausencias sin cubrir"
     * that the coordinator's home module surfaces. Counts covers, not teachers (one absent teacher may
     * have several uncovered slots).
     */
    public function countUnassignedOn(\DateTimeImmutable $date): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.date = :date')
            ->andWhere('c.assignedGuardia IS NULL')
            ->setParameter('date', $date, 'date_immutable')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The parte lines for a date and period, absent teacher and assigned guardia eager-loaded.
     *
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index within the day
     *
     * @return GuardiaCover[] the covers, absent teacher first by name
     */
    public function findForParte(\DateTimeImmutable $date, int $slotIndex): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('absent', 'guardia', 'absence', 'grouping')
            ->join('c.absentTeacher', 'absent')
            ->leftJoin('c.assignedGuardia', 'guardia')
            ->join('c.absence', 'absence')
            // Grouping joined too: the parte reads it on every line (to say which room the class actually
            // happens in), so lazy-loading it would be a query per line.
            ->leftJoin('c.grouping', 'grouping')
            ->andWhere('c.date = :date')
            ->andWhere('c.slotIndex = :slot')
            ->setParameter('date', $date, 'date_immutable')
            ->setParameter('slot', $slotIndex)
            ->orderBy('absent.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * What one guardia COSTS the teacher who does it, as a DQL expression to count distinctly.
     *
     * A cover on its own is one guardia. Several covers folded into the same {@see GuardiaGrouping} are
     * ALSO one guardia between them: the centre's rule (Paco, 30-07-2026) is that minding three groups
     * together in the assembly hall is one session of work, not three — the same reasoning they applied
     * to the two break periods counting as a single duty. Two groups the teacher has to walk between
     * (ungrouped) do still count two: that is two places to be, and the whole point of grouping is to
     * avoid it.
     *
     * Written once here and shared by every counting query, so the equitable split, the per-teacher
     * ranking and the teacher's own tally can never disagree about what a guardia is worth. The 'g'/'c'
     * prefixes keep the two id spaces apart (grouping 5 and cover 5 are different things); CONCAT with a
     * NULL grouping yields NULL, which is what makes COALESCE fall through to the cover's own key.
     */
    private const string WORK_UNIT = "COALESCE(CONCAT('g', IDENTITY(c.grouping)), CONCAT('c', c.id))";

    /**
     * How much guardia work each teacher has done at a given period — the per-slot balance the equitable
     * engine minimises. Counts every assigned cover with no incident (an assigned cover is done by
     * default), with a whole grouping counting as one (see {@see self::WORK_UNIT}). Derived live (never
     * stored), so it cannot drift out of sync.
     *
     * @param int $slotIndex the period index within the day
     *
     * @return array<int, int> map of teacher id → guardias done at that period
     */
    public function loadBySlot(int $slotIndex): array
    {
        return $this->countsKeyedByGuardia(
            $this->createQueryBuilder('c')
                ->andWhere('c.slotIndex = :slot')
                ->setParameter('slot', $slotIndex),
        );
    }

    /**
     * How much guardia work each teacher has done in total, across every period — the tiebreaker when
     * two candidates are level on the per-slot balance. A grouping counts as one (see
     * {@see self::WORK_UNIT}).
     *
     * @return array<int, int> map of teacher id → guardias done
     */
    public function totalLoad(): array
    {
        return $this->countsKeyedByGuardia($this->createQueryBuilder('c'));
    }

    /**
     * The guardias assigned to a teacher on a date, absent teacher eager-loaded — the teacher's own
     * "mis guardias de hoy" view. Ordered by period so it reads top-to-bottom through the day.
     *
     * @param User               $guardia the assigned guardia teacher
     * @param \DateTimeImmutable $date    the day
     *
     * @return GuardiaCover[] the covers assigned to that teacher that day, earliest period first
     */
    public function findAssignedTo(User $guardia, \DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('absent', 'grouping')
            ->join('c.absentTeacher', 'absent')
            ->leftJoin('c.grouping', 'grouping')
            ->andWhere('c.assignedGuardia = :guardia')
            ->andWhere('c.date = :date')
            ->setParameter('guardia', $guardia)
            ->setParameter('date', $date, 'date_immutable')
            ->orderBy('c.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The guardias assigned to a teacher within a day range (inclusive), earliest day and period first —
     * used by the calendar to lay them out by day alongside tasks and events. The absent teacher is
     * eager-loaded so the list reads without extra queries.
     *
     * @param User               $guardia the guardia teacher
     * @param \DateTimeImmutable $start   the first day (inclusive)
     * @param \DateTimeImmutable $end     the last day (inclusive)
     *
     * @return GuardiaCover[] the covers assigned to them in that range, chronological
     */
    public function findAssignedToBetween(User $guardia, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('absent', 'grouping')
            ->join('c.absentTeacher', 'absent')
            ->leftJoin('c.grouping', 'grouping')
            ->andWhere('c.assignedGuardia = :guardia')
            ->andWhere('c.date BETWEEN :start AND :end')
            ->setParameter('guardia', $guardia)
            ->setParameter('start', $start, 'date_immutable')
            ->setParameter('end', $end, 'date_immutable')
            ->orderBy('c.date', 'ASC')
            ->addOrderBy('c.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The guardias assigned to a teacher from a date onwards (today included), earliest day and period
     * first — everything they still have to cover, for their own "mis guardias" screen. The absent
     * teacher is eager-loaded so the list reads without extra queries.
     *
     * @param User               $guardia the guardia teacher
     * @param \DateTimeImmutable $from    the first day to include (typically today)
     *
     * @return GuardiaCover[] the upcoming covers assigned to them, chronological
     */
    public function findUpcomingAssignedTo(User $guardia, \DateTimeImmutable $from): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('absent', 'grouping')
            ->join('c.absentTeacher', 'absent')
            ->leftJoin('c.grouping', 'grouping')
            ->andWhere('c.assignedGuardia = :guardia')
            ->andWhere('c.date >= :from')
            ->setParameter('guardia', $guardia)
            ->setParameter('from', $from, 'date_immutable')
            ->orderBy('c.date', 'ASC')
            ->addOrderBy('c.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The guardias a teacher covered BEFORE a date (their history), most recent first — the "mi
     * histórico" table. The absent teacher is eager-loaded so the list reads without extra queries.
     *
     * @param User               $guardia the guardia teacher
     * @param \DateTimeImmutable $before  the exclusive upper bound (typically today)
     *
     * @return GuardiaCover[] the past covers assigned to them, most recent first
     */
    public function findPastAssignedTo(User $guardia, \DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('absent', 'grouping')
            ->join('c.absentTeacher', 'absent')
            ->leftJoin('c.grouping', 'grouping')
            ->andWhere('c.assignedGuardia = :guardia')
            ->andWhere('c.date < :before')
            ->setParameter('guardia', $guardia)
            ->setParameter('before', $before, 'date_immutable')
            ->orderBy('c.date', 'DESC')
            ->addOrderBy('c.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The covers of one day that could still get the "apunta las ausencias en RAICES" reminder: assigned
     * to somebody, without a registered incident (nobody covered it, so there is no roll to take) and not
     * reminded yet. WHICH of them are actually due is a matter of the clock and belongs to
     * {@see \App\Service\GuardiaRaicesReminder} — the period times live in the timetable, not here, so
     * this query cannot filter by hour. A day holds a couple of dozen covers at most, so fetching the
     * day and filtering in PHP is cheaper than joining the timetable per row.
     *
     * The assigned teacher is eager-loaded: the sweep needs them as the recipient of every notice.
     *
     * @param \DateTimeImmutable $date the day to sweep
     *
     * @return GuardiaCover[] the candidate covers, earliest period first
     */
    public function findRaicesRemindableOn(\DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('guardia')
            ->join('c.assignedGuardia', 'guardia')
            ->andWhere('c.date = :date')
            ->andWhere('c.notCovered = false')
            ->andWhere('c.raicesReminderSentAt IS NULL')
            ->setParameter('date', $date, 'date_immutable')
            ->orderBy('c.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Stamps the RAICES reminder as sent on the given covers, in one query.
     *
     * Deliberately a bulk DQL update and not a change on the entities: {@see GuardiaCover} is
     * {@see \App\Contract\Auditable}, so going through the Unit of Work would drop an authorless
     * "modificada" entry into every guardia's history for what is machine bookkeeping — the log is
     * there to answer "who changed this cover and why", and a reminder timestamp is neither.
     *
     * @param list<int>          $ids the covers to stamp
     * @param \DateTimeImmutable $at  the instant to record
     *
     * @return int the number of covers stamped
     */
    public function markRaicesReminderSent(array $ids, \DateTimeImmutable $at): int
    {
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->createQueryBuilder('c')
            ->update()
            ->set('c.raicesReminderSentAt', ':at')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('at', $at)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    /**
     * Ids of the teachers who are themselves absent on a date and period — they must be dropped from
     * the guardia pool (a teacher on call cannot cover while they are away).
     *
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index within the day
     *
     * @return list<int> the absent teachers' ids
     */
    public function absentTeacherIdsAt(\DateTimeImmutable $date, int $slotIndex): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.absentTeacher) AS id')
            ->andWhere('c.date = :date')
            ->andWhere('c.slotIndex = :slot')
            ->setParameter('date', $date, 'date_immutable')
            ->setParameter('slot', $slotIndex)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * Guardias covered per teacher across the whole course, teacher eager-loaded and ordered by count
     * (busiest first). An assigned cover with no incident counts as done, and a whole grouping counts as
     * one (see {@see self::WORK_UNIT}); teachers with none do not appear. Powers the coordinator's stats
     * screen, so this is also what the fairness reading is computed from.
     *
     * Queried from {@see User} as root: DQL forbids selecting a *joined* entity alias alongside scalars,
     * so the teacher must be the root and the covers are joined onto it.
     *
     * @param \DateTimeImmutable|null $from lower date bound (inclusive), or null for the whole history
     * @param \DateTimeImmutable|null $to   upper date bound (inclusive), or null
     *
     * @return list<array{teacher: User, total: int}> the ranking, busiest first
     */
    public function coveredTotalsByTeacher(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('g', 'COUNT(DISTINCT '.self::WORK_UNIT.') AS total')
            ->from(User::class, 'g')
            ->join(GuardiaCover::class, 'c', 'WITH', 'c.assignedGuardia = g')
            ->andWhere('c.notCovered = false')
            ->groupBy('g.id')
            ->orderBy('total', 'DESC')
            ->addOrderBy('g.fullName', 'ASC');
        $this->applyWindow($qb, $from, $to);

        /** @var list<array{0: User, total: int}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(
            static fn (array $r): array => ['teacher' => $r[0], 'total' => (int) $r['total']],
            $rows,
        );
    }

    /**
     * How many guardias a single teacher has covered this course (assigned, no incident, a grouping
     * counting as one — see {@see self::WORK_UNIT}) — the counter the teacher sees on their own screen.
     * Derived live, like every other guardia count, and from the same expression, so their tally and the
     * coordinator's ranking always agree.
     *
     * @param User $teacher the guardia teacher
     *
     * @return int the teacher's covered-guardia count
     */
    public function countCoveredForTeacher(User $teacher): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(DISTINCT '.self::WORK_UNIT.')')
            ->andWhere('c.assignedGuardia = :teacher')
            ->andWhere('c.notCovered = false')
            ->setParameter('teacher', $teacher)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Headline coverage figures for the whole course: how many absences were registered, how many got
     * covered (assigned, no incident), how many ended as an incident (nobody covered), and how many are
     * still unassigned. The health check of the guardia service in one row.
     *
     * @param \DateTimeImmutable|null $from lower date bound (inclusive), or null for the whole history
     * @param \DateTimeImmutable|null $to   upper date bound (inclusive), or null
     *
     * @return array{absences: int, covered: int, incidents: int, unassigned: int} the counts
     */
    public function coverageSummary(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select(
                'COUNT(c.id) AS absences',
                'SUM(CASE WHEN c.assignedGuardia IS NOT NULL AND c.notCovered = false THEN 1 ELSE 0 END) AS covered',
                'SUM(CASE WHEN c.notCovered = true THEN 1 ELSE 0 END) AS incidents',
                'SUM(CASE WHEN c.assignedGuardia IS NULL AND c.notCovered = false THEN 1 ELSE 0 END) AS unassigned',
            );
        $this->applyWindow($qb, $from, $to);

        /** @var array{absences: int|string, covered: int|string, incidents: int|string, unassigned: int|string} $row */
        $row = $qb->getQuery()->getSingleResult();

        return [
            'absences' => (int) $row['absences'],
            'covered' => (int) $row['covered'],
            'incidents' => (int) $row['incidents'],
            'unassigned' => (int) $row['unassigned'],
        ];
    }

    /**
     * How many absences fell on each period this course, keyed by slot index — shows where cover is
     * needed most across the day.
     *
     * @return array<int, int> map of slot index → absence count
     */
    public function absencesBySlot(): array
    {
        /** @var list<array{slot: int, total: int}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.slotIndex AS slot', 'COUNT(c.id) AS total')
            ->groupBy('c.slotIndex')
            ->orderBy('c.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();

        $bySlot = [];
        foreach ($rows as $row) {
            $bySlot[(int) $row['slot']] = (int) $row['total'];
        }

        return $bySlot;
    }

    /**
     * The teachers absent most this course, teacher eager-loaded, busiest first — a different lens for
     * leadership (who is away, not who covers). Queried from {@see User} as root, like
     * {@see coveredTotalsByTeacher()} (DQL cannot select a joined entity alongside scalars).
     *
     * @param int                     $limit how many to return
     * @param \DateTimeImmutable|null  $from  lower date bound (inclusive), or null for the whole history
     * @param \DateTimeImmutable|null  $to    upper date bound (inclusive), or null
     *
     * @return list<array{teacher: User, total: int}> the ranking, most absences first
     */
    public function absencesByTeacher(int $limit = 10, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('g', 'COUNT(c.id) AS total')
            ->from(User::class, 'g')
            ->join(GuardiaCover::class, 'c', 'WITH', 'c.absentTeacher = g')
            ->groupBy('g.id')
            ->orderBy('total', 'DESC')
            ->addOrderBy('g.fullName', 'ASC')
            ->setMaxResults($limit);
        $this->applyWindow($qb, $from, $to);

        /** @var list<array{0: User, total: int}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(
            static fn (array $r): array => ['teacher' => $r[0], 'total' => (int) $r['total']],
            $rows,
        );
    }

    /**
     * The parte lines matching the coordinator's history filters, absent teacher and assigned guardia
     * eager-loaded, most recent first. Every filter is optional; passing none returns the full log.
     *
     * @param \DateTimeImmutable|null $from            lower date bound (inclusive)
     * @param \DateTimeImmutable|null $to              upper date bound (inclusive)
     * @param string|null             $group           exact group name to match
     * @param User|null               $assignedTeacher the guardia teacher who covered
     * @param User|null               $absentTeacher   the teacher who was absent
     *
     * @return GuardiaCover[] the matching covers, most recent first
     */
    public function history(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?string $group, ?User $assignedTeacher, ?User $absentTeacher): array
    {
        $qb = $this->createQueryBuilder('c')
            ->addSelect('absent', 'guardia', 'grouping')
            ->join('c.absentTeacher', 'absent')
            ->leftJoin('c.assignedGuardia', 'guardia')
            ->leftJoin('c.grouping', 'grouping')
            ->orderBy('c.date', 'DESC')
            ->addOrderBy('c.slotIndex', 'ASC')
            ->addOrderBy('absent.fullName', 'ASC');

        if (null !== $from) {
            $qb->andWhere('c.date >= :from')->setParameter('from', $from, 'date_immutable');
        }
        if (null !== $to) {
            $qb->andWhere('c.date <= :to')->setParameter('to', $to, 'date_immutable');
        }
        if (null !== $group && '' !== $group) {
            $qb->andWhere('c.groupName = :group')->setParameter('group', $group);
        }
        if (null !== $assignedTeacher) {
            $qb->andWhere('c.assignedGuardia = :assigned')->setParameter('assigned', $assignedTeacher);
        }
        if (null !== $absentTeacher) {
            $qb->andWhere('c.absentTeacher = :absent')->setParameter('absent', $absentTeacher);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Lightweight rows for the analytics dashboard — one per cover, with just the fields the time and
     * heatmap aggregations need. Aggregating in PHP (small volume: hundreds of covers per course) keeps
     * the queries portable and avoids per-driver date functions.
     *
     * @param \DateTimeImmutable|null $from lower date bound (inclusive), or null for the whole history
     * @param \DateTimeImmutable|null $to   upper date bound (inclusive), or null
     *
     * @return list<array{date: \DateTimeImmutable, slot: int, assigned: bool, incident: bool}> the rows
     */
    public function analyticsRows(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.date AS date', 'c.slotIndex AS slot', 'IDENTITY(c.assignedGuardia) AS assigned', 'c.notCovered AS incident');
        $this->applyWindow($qb, $from, $to);

        /** @var list<array{date: \DateTimeImmutable, slot: int, assigned: int|null, incident: bool}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(
            static fn (array $r): array => [
                'date' => $r['date'],
                'slot' => (int) $r['slot'],
                'assigned' => null !== $r['assigned'],
                'incident' => (bool) $r['incident'],
            ],
            $rows,
        );
    }

    /**
     * How many absences each department generated this course (by the absent teacher's department),
     * busiest first. Teachers with no department fall under "Sin departamento".
     *
     * @param \DateTimeImmutable|null $from lower date bound (inclusive), or null for the whole history
     * @param \DateTimeImmutable|null $to   upper date bound (inclusive), or null
     *
     * @return list<array{name: string, total: int}> the ranking, most absences first
     */
    public function absencesByDepartment(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('d.name AS name', 'COUNT(c.id) AS total')
            ->join('c.absentTeacher', 't')
            ->leftJoin('t.unit', 'd')
            ->groupBy('d.id')
            ->orderBy('total', 'DESC')
            ->addOrderBy('d.name', 'ASC');
        $this->applyWindow($qb, $from, $to);

        /** @var list<array{name: string|null, total: int}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(
            static fn (array $r): array => ['name' => $r['name'] ?? 'Sin departamento', 'total' => (int) $r['total']],
            $rows,
        );
    }

    /**
     * Constrains a query to an inclusive date window over the {@code c.date} column, skipping any bound
     * that is null. The alias {@code c} must be the cover in the given builder (root or joined).
     *
     * @param \Doctrine\ORM\QueryBuilder $qb   the builder to constrain
     * @param \DateTimeImmutable|null    $from lower bound (inclusive), or null
     * @param \DateTimeImmutable|null    $to   upper bound (inclusive), or null
     */
    private function applyWindow(\Doctrine\ORM\QueryBuilder $qb, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): void
    {
        if (null !== $from) {
            $qb->andWhere('c.date >= :winFrom')->setParameter('winFrom', $from, 'date_immutable');
        }
        if (null !== $to) {
            $qb->andWhere('c.date <= :winTo')->setParameter('winTo', $to, 'date_immutable');
        }
    }

    /**
     * Runs an incident-free count of guardia work per teacher and returns it keyed by teacher id, with a
     * grouping counting as one (see {@see self::WORK_UNIT}).
     *
     * @param \Doctrine\ORM\QueryBuilder $qb a builder already scoped (e.g. by slot), alias {@code c}
     *
     * @return array<int, int> map of teacher id → guardias done
     */
    private function countsKeyedByGuardia(\Doctrine\ORM\QueryBuilder $qb): array
    {
        /** @var list<array{id: int, total: int}> $rows */
        $rows = $qb
            ->select('IDENTITY(c.assignedGuardia) AS id', 'COUNT(DISTINCT '.self::WORK_UNIT.') AS total')
            ->andWhere('c.notCovered = false')
            ->andWhere('c.assignedGuardia IS NOT NULL')
            ->groupBy('c.assignedGuardia')
            ->getQuery()
            ->getResult();

        $load = [];
        foreach ($rows as $row) {
            $load[(int) $row['id']] = (int) $row['total'];
        }

        return $load;
    }
}
