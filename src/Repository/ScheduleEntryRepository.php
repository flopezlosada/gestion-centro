<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\ScheduleEntrySource;
use App\Enum\Weekday;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
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
     * The teaching cells a teacher has on a weekday at a given period of a course — usually one, but
     * several when the period is a multi-group activity (Peñalara lists the teacher against every
     * group at once, e.g. a whole-level session in the assembly hall). Empty when they are free then.
     * Used to snapshot the group(s) and room an absence leaves uncovered.
     *
     * @param AcademicYear $year      the course whose timetable to read
     * @param User         $teacher   the (absent) teacher
     * @param Weekday      $weekday   the weekday
     * @param int          $slotIndex the period index within the day
     *
     * @return ScheduleEntry[] the lective entries at that period (empty if free), group name ascending
     */
    public function lectiveEntriesAt(AcademicYear $year, User $teacher, Weekday $weekday, int $slotIndex): array
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
            ->orderBy('s.groupName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The period indices a teacher teaches on a weekday of a course — the slots an all-day absence
     * turns into covers (a free period or a duty slot needs no cover, so only lective ones count).
     *
     * @param AcademicYear $year    the course whose timetable to read
     * @param User         $teacher the (absent) teacher
     * @param Weekday      $weekday the weekday
     *
     * @return list<int> the lective period indices, earliest first
     */
    public function lectiveSlotsFor(AcademicYear $year, User $teacher, Weekday $weekday): array
    {
        /** @var list<array{slotIndex: int}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT s.slotIndex AS slotIndex')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.teacher = :teacher')
            ->andWhere('s.weekday = :weekday')
            ->andWhere('s.kind = :lective')
            ->setParameter('year', $year)
            ->setParameter('teacher', $teacher)
            ->setParameter('weekday', $weekday)
            ->setParameter('lective', ScheduleActivityKind::LECTIVE)
            ->orderBy('s.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $r): int => (int) $r['slotIndex'], $rows);
    }

    /**
     * The teacher's lective classes on a weekday of a course, earliest period first — the rows the
     * "apuntar ausencia" screen lists so the coordinator ticks the periods missed and leaves a task per
     * class (each carries its group, room, subject and time to read without another query).
     *
     * @param AcademicYear $year    the course whose timetable to read
     * @param User         $teacher the (absent) teacher
     * @param Weekday      $weekday the weekday
     *
     * @return ScheduleEntry[] the lective entries that day, earliest period first
     */
    public function lectiveDayFor(AcademicYear $year, User $teacher, Weekday $weekday): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.teacher = :teacher')
            ->andWhere('s.weekday = :weekday')
            ->andWhere('s.kind = :lective')
            ->setParameter('year', $year)
            ->setParameter('teacher', $teacher)
            ->setParameter('weekday', $weekday)
            ->setParameter('lective', ScheduleActivityKind::LECTIVE)
            ->orderBy('s.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
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
        // DQL aggregate functions (MIN) are hydrated as raw scalars, not through the field's type, so
        // the times come back as strings ("08:25:00") — convert them so callers get the DateTimeImmutable
        // the signature promises (a raw string reaching ScheduleEntry::setStartsAt() would fatal).
        /** @var list<array{slotIndex: int, startsAt: string, endsAt: string}> $rows */
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
                'startsAt' => new \DateTimeImmutable($r['startsAt']),
                'endsAt' => new \DateTimeImmutable($r['endsAt']),
            ],
            $rows,
        );
    }

    /**
     * The year's distinct slots reshaped by index for O(1) lookup of a slot's times:
     * [slotIndex => ['startsAt' => ..., 'endsAt' => ...]]. Shared by the parte, "mis guardias" and the
     * home hero, which all resolve a cover's times from its slot index. Empty if the year has no schedule.
     *
     * @return array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}>
     */
    public function slotTimes(?AcademicYear $year): array
    {
        if (null === $year) {
            return [];
        }

        $times = [];
        foreach ($this->distinctSlots($year) as $slot) {
            $times[$slot['index']] = ['startsAt' => $slot['startsAt'], 'endsAt' => $slot['endsAt']];
        }

        return $times;
    }

    /**
     * Every timetable cell a teacher has in a course, of any kind, ordered by weekday then period —
     * the data behind the manual "horario de guardias" grid, which shows the imported lective cells as
     * read-only context and lets the duty cells be edited.
     *
     * @param AcademicYear $year    the course whose timetable to read
     * @param User         $teacher the teacher
     *
     * @return ScheduleEntry[] the teacher's cells in that course
     */
    public function findByTeacherAndYear(AcademicYear $year, User $teacher): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.teacher = :teacher')
            ->setParameter('year', $year)
            ->setParameter('teacher', $teacher)
            ->orderBy('s.weekday', 'ASC')
            ->addOrderBy('s.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The hand-marked duty cells of a course, indexed for the importer as teacher id → "weekday:slot" →
     * row id. Read in one query (never one per teacher) so the import can tell, for every cell it is
     * about to write, whether a person already owns that period: a duty cell is then skipped (nobody
     * ends up in the guardia pool twice) and a lesson wins, dropping the hand-marked guardia by id.
     * Empty when every cell in the course came from an export.
     *
     * @param AcademicYear $year the course whose timetable to read
     *
     * @return array<int, array<string, int>> teacher id → "weekday:slotIndex" → schedule entry id
     */
    public function manualDutyCells(AcademicYear $year): array
    {
        // A scalar select still hydrates an enumType column into its enum (unlike MIN() in distinctSlots,
        // which bypasses the field type), so weekday arrives as a Weekday — read its ISO value.
        /** @var list<array{id: int, teacherId: int, weekday: Weekday, slotIndex: int}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.id AS id', 'IDENTITY(s.teacher) AS teacherId', 's.weekday AS weekday', 's.slotIndex AS slotIndex')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.source = :manual')
            ->setParameter('year', $year)
            ->setParameter('manual', ScheduleEntrySource::MANUAL)
            ->getQuery()
            ->getResult();

        $cells = [];
        foreach ($rows as $row) {
            $cells[(int) $row['teacherId']][$row['weekday']->value.':'.(int) $row['slotIndex']] = (int) $row['id'];
        }

        return $cells;
    }

    /**
     * How many imported cells the given teachers already have in a course — what a re-import is about to
     * replace. Feeds the preview so the equipo directivo sees "1.240 celdas sustituyen a las 1.234 que
     * ya había" before committing, instead of uploading blind.
     *
     * @param AcademicYear $year     the course whose timetable to count
     * @param list<User>   $teachers the teachers the export resolved to
     *
     * @return int the number of export-sourced cells those teachers hold in that course
     */
    public function countImportedFor(AcademicYear $year, array $teachers): int
    {
        if ([] === $teachers) {
            return 0;
        }

        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.teacher IN (:teachers)')
            ->andWhere('s.source = :penalara')
            ->setParameter('year', $year)
            ->setParameter('teachers', $teachers)
            ->setParameter('penalara', ScheduleEntrySource::PENALARA)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The teachers who already have any timetable cell in a course, name ascending. The importer
     * compares this with the teachers the export resolved to, so it can report the ones who kept a
     * timetable nobody re-imported — someone who left the centre mid-course would otherwise stay in the
     * guardia pool for ever, since an import only ever touches the teachers it found.
     *
     * @param AcademicYear $year the course whose timetable to read
     *
     * @return User[] the teachers with at least one cell in that course
     */
    public function teachersWithEntries(AcademicYear $year): array
    {
        // Rooted on User, not on ScheduleEntry: DQL cannot select an entity reached only through a
        // join without also selecting the FROM alias, and here the teachers are what we are after.
        return $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT t')
            ->from(User::class, 't')
            ->join(ScheduleEntry::class, 's', Join::WITH, 's.teacher = t')
            ->andWhere('s.academicYear = :year')
            ->setParameter('year', $year)
            ->orderBy('t.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Replaces only a teacher's guardia and collaborator cells in one course, leaving every lective
     * cell untouched. This backs the manual fallback for when Peñalara imports the timetable but not
     * the guardias: the equipo directivo marks the duty slots by hand, and re-saving wipes just the
     * previously-marked duty cells (never the imported lessons) before inserting the fresh ones — the
     * delete and inserts run in one transaction so a concurrent parte read never sees neither set.
     *
     * The delete spans duty cells of BOTH sources on purpose: the grid the editor posts back is the
     * whole truth about that teacher's duties, so an imported guardia the person unticked has to go,
     * and one they left ticked is re-inserted as theirs ({@see ScheduleEntrySource::MANUAL}). Editing a
     * teacher by hand therefore takes their guardias out of the export's reach — the screen says so.
     *
     * @param AcademicYear        $year     the course whose duty cells are replaced
     * @param User                $teacher  the teacher whose duty cells are replaced
     * @param list<ScheduleEntry> $entries  the fresh guardia/collaborator cells to persist
     */
    public function replaceDutySlotsForTeacher(AcademicYear $year, User $teacher, array $entries): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(function () use ($em, $year, $teacher, $entries): void {
            $this->createQueryBuilder('s')
                ->delete()
                ->where('s.academicYear = :year')
                ->andWhere('s.teacher = :teacher')
                ->andWhere('s.kind IN (:kinds)')
                ->setParameter('year', $year)
                ->setParameter('teacher', $teacher)
                ->setParameter('kinds', [ScheduleActivityKind::GUARDIA, ScheduleActivityKind::COLLABORATOR])
                ->getQuery()
                ->execute();
            foreach ($entries as $entry) {
                $em->persist($entry);
            }
            $em->flush();
        });
    }

    /**
     * Replaces the given teachers' IMPORTED timetable for one course with the supplied entries. Used by
     * the importer: it wipes only the reconciled teachers' export-sourced rows in that course (so
     * unmatched teachers, every other course, and every hand-marked guardia keep whatever they had) and
     * inserts the fresh ones, making a re-import of the same course idempotent. The delete and the
     * inserts run in one transaction, so a concurrent parte read (reachable any time through the
     * self-service import screen) never sees a half-replaced timetable — either the old rows or the new
     * ones, never neither.
     *
     * @param AcademicYear        $year          the course whose entries are replaced
     * @param list<User>          $teachers      the teachers whose old imported entries in that course are cleared
     * @param list<ScheduleEntry> $entries       the fresh entries to persist
     * @param list<int>           $dropManualIds ids of hand-marked cells a new lesson lands on, cleared too
     */
    public function replaceForTeachers(AcademicYear $year, array $teachers, array $entries, array $dropManualIds = []): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(function () use ($em, $year, $teachers, $entries, $dropManualIds): void {
            if ([] !== $teachers) {
                $this->createQueryBuilder('s')
                    ->delete()
                    ->where('s.academicYear = :year')
                    ->andWhere('s.teacher IN (:teachers)')
                    ->andWhere('s.source = :penalara')
                    ->setParameter('year', $year)
                    ->setParameter('teachers', $teachers)
                    ->setParameter('penalara', ScheduleEntrySource::PENALARA)
                    ->getQuery()
                    ->execute();
            }
            // A hand-marked guardia the new timetable turns into a lesson has to go: leaving it would put
            // the teacher in the guardia pool for a period they are now teaching.
            if ([] !== $dropManualIds) {
                $this->createQueryBuilder('s')
                    ->delete()
                    ->where('s.id IN (:ids)')
                    ->setParameter('ids', $dropManualIds)
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
