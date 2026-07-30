<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Room;
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
     * Who is teaching at a weekday and period, and what — teacher id → the group names they have then.
     * Read to warn before signing somebody up as guardia support ({@see \App\Entity\GuardiaSupport}): the
     * normal case is precisely that the timetable says they are teaching and reality says otherwise
     * (their Bachillerato group has finished lessons), so this is a warning to a human, never a filter.
     *
     * @param AcademicYear $year      the course whose timetable to read
     * @param Weekday      $weekday   the weekday
     * @param int          $slotIndex the period index within the day
     *
     * @return array<int, list<string>> teacher id → the groups they teach then (never empty per key)
     */
    public function lectiveGroupsByTeacherAt(AcademicYear $year, Weekday $weekday, int $slotIndex): array
    {
        /** @var list<array{teacherId: int, groupName: string|null}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.teacher) AS teacherId', 's.groupName AS groupName')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.weekday = :weekday')
            ->andWhere('s.slotIndex = :slot')
            ->andWhere('s.kind = :lective')
            ->setParameter('year', $year)
            ->setParameter('weekday', $weekday)
            ->setParameter('slot', $slotIndex)
            ->setParameter('lective', ScheduleActivityKind::LECTIVE)
            ->getQuery()
            ->getResult();

        $byTeacher = [];
        foreach ($rows as $row) {
            $byTeacher[(int) $row['teacherId']][] = $row['groupName'] ?? 'sin grupo';
        }

        return $byTeacher;
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
     * Every room the course's timetable mentions, alphabetically — the universe of spaces the centre
     * actually uses, derived from the timetable instead of from a document that goes stale. A room
     * nobody ever has a class in is invisible here, which is the honest limit of deriving it: we can only
     * know about the rooms somebody uses.
     *
     * @param AcademicYear $year the course whose timetable to read
     *
     * @return list<string> the room short names, alphabetically
     */
    public function distinctRooms(AcademicYear $year): array
    {
        /** @var list<array{roomName: string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT s.roomName AS roomName')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.roomName IS NOT NULL')
            ->andWhere("s.roomName <> ''")
            ->setParameter('year', $year)
            ->orderBy('s.roomName', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $r): string => $r['roomName'], $rows);
    }

    /**
     * The classes taking place in a room at a weekday and period, teachers eager-loaded — who would have
     * to be moved to free up a big room for a grouped guardia, and therefore who must be told.
     *
     * @param AcademicYear $year      the course whose timetable to read
     * @param Weekday      $weekday   the weekday
     * @param int          $slotIndex the period index within the day
     *
     * @return ScheduleEntry[] the lective entries that have a room then, by room and group
     */
    public function lectiveEntriesWithRoomAt(AcademicYear $year, Weekday $weekday, int $slotIndex): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('t')
            ->join('s.teacher', 't')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.weekday = :weekday')
            ->andWhere('s.slotIndex = :slot')
            ->andWhere('s.kind = :lective')
            ->andWhere('s.roomName IS NOT NULL')
            ->andWhere("s.roomName <> ''")
            ->setParameter('year', $year)
            ->setParameter('weekday', $weekday)
            ->setParameter('slot', $slotIndex)
            ->setParameter('lective', ScheduleActivityKind::LECTIVE)
            ->orderBy('s.roomName', 'ASC')
            ->addOrderBy('s.groupName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Which rooms are taken at each period of a weekday: period index → room short names. One query for
     * the whole day, so the "aulas libres" sheet can list every period without a query per row.
     *
     * @param AcademicYear $year    the course whose timetable to read
     * @param Weekday      $weekday the weekday
     *
     * @return array<int, list<string>> period index → the rooms in use then, alphabetically
     */
    public function occupiedRoomsBySlot(AcademicYear $year, Weekday $weekday): array
    {
        /** @var list<array{slotIndex: int, roomName: string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT s.slotIndex AS slotIndex', 's.roomName AS roomName')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.weekday = :weekday')
            ->andWhere('s.kind = :lective')
            ->andWhere('s.roomName IS NOT NULL')
            ->andWhere("s.roomName <> ''")
            ->setParameter('year', $year)
            ->setParameter('weekday', $weekday)
            ->setParameter('lective', ScheduleActivityKind::LECTIVE)
            ->orderBy('s.slotIndex', 'ASC')
            ->addOrderBy('s.roomName', 'ASC')
            ->getQuery()
            ->getResult();

        $bySlot = [];
        foreach ($rows as $row) {
            $bySlot[(int) $row['slotIndex']][] = $row['roomName'];
        }

        return $bySlot;
    }

    /**
     * How many groups each room has been seen holding AT ONCE anywhere in the course's timetable —
     * evidence of which rooms are the big ones, instead of a capacity somebody would have to type in and
     * keep up to date. The assembly hall comes out at 8 because Peñalara really does put eight groups in
     * it at the same time; an ordinary classroom comes out at 1.
     *
     * Not a real capacity and not called one: it is a floor ("has held at least this many"), which is
     * exactly what is needed to sort rooms by how much they can take. A proper capacity belongs to the
     * spaces module, with its own entity.
     *
     * @param AcademicYear $year the course whose timetable to read
     *
     * @return array<string, int> room short name → most groups seen in it simultaneously
     */
    public function observedRoomCapacity(AcademicYear $year): array
    {
        // COUNT(DISTINCT …) is an aggregate, so it comes back as a raw scalar string, not through any
        // field type — cast it (see distinctSlots() for the same trap with MIN()).
        /** @var list<array{roomName: string, groups: string|int}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.roomName AS roomName', 'COUNT(DISTINCT s.groupName) AS groups')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.kind = :lective')
            ->andWhere('s.roomName IS NOT NULL')
            ->andWhere("s.roomName <> ''")
            ->setParameter('year', $year)
            ->setParameter('lective', ScheduleActivityKind::LECTIVE)
            ->groupBy('s.roomName')
            ->addGroupBy('s.weekday')
            ->addGroupBy('s.slotIndex')
            ->getQuery()
            ->getResult();

        $capacity = [];
        foreach ($rows as $row) {
            $capacity[$row['roomName']] = max($capacity[$row['roomName']] ?? 0, (int) $row['groups']);
        }

        return $capacity;
    }

    /**
     * How many teaching periods a week each teacher has in a course, keyed by teacher id.
     *
     * The quota screen shows it beside the quota box: whoever decides that a colleague takes on three
     * guardias and another one takes on one is really comparing teaching loads, and having to open the
     * timetable in another tab to do it is how quotas end up typed at random.
     *
     * Periods are counted, not rows: Peñalara lists a teacher once per group when several share a slot
     * (the Salón de Actos activities), and counting rows would make those teachers look twice as busy as
     * they are.
     *
     * @param AcademicYear $year the course whose timetable to read
     *
     * @return array<int, int> teacher id → teaching periods a week
     */
    public function lectiveHoursByTeacher(AcademicYear $year): array
    {
        // One row per occupied (teacher, weekday, slot) and the tally done here, rather than a
        // COUNT(DISTINCT <expression>) that would need CONCAT or arithmetic over an enumType column to
        // fold the pair into one value. The grouped read is a few hundred rows of three small integers,
        // and it behaves the same on every engine — this repository has already been bitten twice by
        // DQL that looked right and hydrated wrong (see distinctSlots() and its MIN()).
        /** @var list<array{teacher: string|int}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.teacher) AS teacher')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.kind = :lective')
            ->setParameter('year', $year)
            ->setParameter('lective', ScheduleActivityKind::LECTIVE)
            ->groupBy('s.teacher')
            ->addGroupBy('s.weekday')
            ->addGroupBy('s.slotIndex')
            ->getQuery()
            ->getResult();

        $hours = [];
        foreach ($rows as $row) {
            $teacherId = (int) $row['teacher'];
            $hours[$teacherId] = ($hours[$teacherId] ?? 0) + 1;
        }

        return $hours;
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
     * The distinct subjects actually taught in a course, alphabetically — the centre's real subject
     * names, spelled exactly as the parte snapshots them from the timetable.
     *
     * This is what the guardia task bank offers when a department labels a task: a bank task has to be
     * of the subject the group was going to have, and two people typing "Lengua" and "Lengua Castellana"
     * by hand would never match. Reading the list off the timetable makes the match exact by
     * construction, with no catalogue to maintain.
     *
     * @param AcademicYear|null $year the course whose timetable to read, or null for none
     *
     * @return list<string> the subject names, alphabetically
     */
    public function distinctSubjects(?AcademicYear $year): array
    {
        if (null === $year) {
            return [];
        }

        /** @var list<array{subject: string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT s.subjectName AS subject')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.subjectName IS NOT NULL')
            ->setParameter('year', $year)
            ->orderBy('subject', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $r): string => $r['subject'], $rows);
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
     * The teaching periods of a course as teacher id → weekday → period indexes, which is the shape the
     * rota engine needs to know who is free when.
     *
     * Grouped rather than counted, so a teacher listed once per group in the same period (the Salón de
     * Actos activities Peñalara exports that way) appears once.
     *
     * @param AcademicYear $year the course whose timetable to read
     *
     * @return array<int, array<int, list<int>>> teacher id → weekday → the period indexes they teach
     */
    public function lectiveSlotsByTeacher(AcademicYear $year): array
    {
        /** @var list<array{teacher: string|int, weekday: Weekday, slotIndex: int}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.teacher) AS teacher', 's.weekday AS weekday', 's.slotIndex AS slotIndex')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.kind = :lective')
            ->setParameter('year', $year)
            ->setParameter('lective', ScheduleActivityKind::LECTIVE)
            ->groupBy('s.teacher')
            ->addGroupBy('s.weekday')
            ->addGroupBy('s.slotIndex')
            ->getQuery()
            ->getResult();

        $byTeacher = [];
        foreach ($rows as $row) {
            // A scalar select hydrates an enumType column into its enum, so weekday arrives as a Weekday.
            $byTeacher[(int) $row['teacher']][$row['weekday']->value][] = (int) $row['slotIndex'];
        }

        return $byTeacher;
    }

    /**
     * Replaces every duty cell the proposal engine owns in a course with a fresh set, in one transaction.
     *
     * Only its own: cells a person marked by hand and cells from the export are left untouched, which is
     * what lets a rota be re-proposed without undoing somebody's retouch.
     *
     * @param AcademicYear       $year    the course being redrawn
     * @param list<ScheduleEntry> $entries the fresh duty cells to persist
     */
    public function replaceEngineDutyCells(AcademicYear $year, array $entries): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(function () use ($em, $year, $entries): void {
            $this->createQueryBuilder('s')
                ->delete()
                ->where('s.academicYear = :year')
                ->andWhere('s.source = :engine')
                ->setParameter('year', $year)
                ->setParameter('engine', ScheduleEntrySource::ENGINE)
                ->getQuery()
                ->execute();
            foreach ($entries as $entry) {
                $em->persist($entry);
            }
            $em->flush();
        });
    }

    /**
     * Every duty cell of a course — the current rota — with its teacher joined so a grid can print the
     * names without a query per cell.
     *
     * @param AcademicYear $year the course whose rota to read
     *
     * @return ScheduleEntry[] the duty cells, in reading order
     */
    public function findDutyCells(AcademicYear $year): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('t')
            ->join('s.teacher', 't')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.kind IN (:kinds)')
            ->setParameter('year', $year)
            ->setParameter('kinds', [ScheduleActivityKind::GUARDIA, ScheduleActivityKind::COLLABORATOR])
            ->orderBy('s.weekday', 'ASC')
            ->addOrderBy('s.slotIndex', 'ASC')
            ->addOrderBy('t.fullName', 'ASC')
            ->getQuery()
            ->getResult();
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
     * The duty cells of a course an import must not overwrite — the ones a person marked by hand and the
     * ones the proposal engine placed ({@see ScheduleEntrySource::protectedFromImport()}) — indexed for
     * the importer as teacher id → "weekday:slot" → row id.
     *
     * Read in one query (never one per teacher) so the import can tell, for every cell it is about to
     * write, whether one of them already owns that period: a duty cell is then skipped (nobody ends up in
     * the guardia pool twice) and a lesson wins, dropping the protected guardia by id — otherwise the
     * teacher would be left on guardia in a period they now teach. Empty when every cell in the course
     * came from an export.
     *
     * @param AcademicYear $year the course whose timetable to read
     *
     * @return array<int, array<string, int>> teacher id → "weekday:slotIndex" → schedule entry id
     */
    public function protectedDutyCells(AcademicYear $year): array
    {
        // A scalar select still hydrates an enumType column into its enum (unlike MIN() in distinctSlots,
        // which bypasses the field type), so weekday arrives as a Weekday — read its ISO value.
        /** @var list<array{id: int, teacherId: int, weekday: Weekday, slotIndex: int}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.id AS id', 'IDENTITY(s.teacher) AS teacherId', 's.weekday AS weekday', 's.slotIndex AS slotIndex')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.source IN (:protected)')
            ->setParameter('year', $year)
            ->setParameter('protected', ScheduleEntrySource::protectedFromImport())
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
     * The cells that name a room but point at no catalogued space, as {@code [id, roomName]} pairs —
     * everything the synchroniser needs to know which cards are missing and which rows to link.
     *
     * Deliberately returns the RAW names and leaves the comparison to PHP: matching a room by SQL
     * equality would make the result depend on the database collation (MySQL 8 here, MariaDB on the
     * server) and would still miss "S  ACTOS" against "S ACTOS". Normalising in one place —
     * {@see Room::normaliseCode()} — is the only way both halves agree.
     *
     * @return list<array{id: int, roomName: string}> the unlinked cells
     */
    public function unlinkedRoomCells(): array
    {
        /** @var list<array{id: int, roomName: string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.id AS id', 's.roomName AS roomName')
            ->andWhere('s.room IS NULL')
            ->andWhere('s.roomName IS NOT NULL')
            ->andWhere("TRIM(s.roomName) <> ''")
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'roomName' => $r['roomName']], $rows);
    }

    /**
     * Points the given cells at a space, by id. The ids come from {@see unlinkedRoomCells()}, already
     * matched in PHP, so this never re-derives which cells belong to which room.
     *
     * @param Room      $room the space to link to
     * @param list<int> $ids  the cells to link
     *
     * @return int how many cells were linked
     */
    public function linkCells(Room $room, array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->createQueryBuilder('s')
            ->update()
            ->set('s.room', ':room')
            ->where('s.id IN (:ids)')
            ->setParameter('room', $room)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    /**
     * How many cells name a room but point at no catalogued space — always zero right after a sync.
     * A non-zero count means the occupancy calculation is blind to those cells, so it is surfaced
     * rather than left to be discovered as "that room looked free".
     *
     * @return int the number of unlinked cells
     */
    public function countCellsWithoutRoom(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.room IS NULL')
            ->andWhere('s.roomName IS NOT NULL')
            ->andWhere("TRIM(s.roomName) <> ''")
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Who is in which space at one period of one weekday: every lective cell of that course, period and
     * weekday that has a catalogued room, with its teacher joined so the screen can name them without a
     * query per row.
     *
     * This is the raw occupancy the space module reads. Guardia and collaborator cells are excluded —
     * they occupy nobody's room — and so are cells with no catalogued space, which is why the sync has
     * to have run (see {@see countCellsWithoutRoom()}).
     *
     * @param AcademicYear $year      the course whose timetable to read
     * @param Weekday      $weekday   the weekday
     * @param int          $slotIndex the period index within the day
     *
     * @return ScheduleEntry[] the occupying lessons, teachers joined
     */
    public function occupancyAt(AcademicYear $year, Weekday $weekday, int $slotIndex): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('t', 'r')
            ->join('s.teacher', 't')
            ->join('s.room', 'r')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.weekday = :weekday')
            ->andWhere('s.slotIndex = :slot')
            ->andWhere('s.kind = :lective')
            ->setParameter('year', $year)
            ->setParameter('weekday', $weekday)
            ->setParameter('slot', $slotIndex)
            ->setParameter('lective', ScheduleActivityKind::LECTIVE)
            ->orderBy('r.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * What a course's timetable holds, in one query: how many cells, how many of them are guardia or
     * collaborator duty, and how many teachers it covers. Drives the "arranque de curso" checklist,
     * which has to say at a glance whether the timetable is loaded and whether it brought the guardias
     * (an export taken from the wrong Peñalara menu carries lessons but no duty slots).
     *
     * @param AcademicYear $year the course whose timetable to summarise
     *
     * @return array{cells: int, duty: int, teachers: int} the figures
     */
    public function summaryFor(AcademicYear $year): array
    {
        /** @var array{cells: int|string, duty: int|string, teachers: int|string} $row */
        $row = $this->createQueryBuilder('s')
            ->select(
                'COUNT(s.id) AS cells',
                'SUM(CASE WHEN s.kind IN (:duty) THEN 1 ELSE 0 END) AS duty',
                'COUNT(DISTINCT IDENTITY(s.teacher)) AS teachers',
            )
            ->andWhere('s.academicYear = :year')
            ->setParameter('year', $year)
            ->setParameter('duty', [ScheduleActivityKind::GUARDIA, ScheduleActivityKind::COLLABORATOR])
            ->getQuery()
            ->getSingleResult();

        return ['cells' => (int) $row['cells'], 'duty' => (int) $row['duty'], 'teachers' => (int) $row['teachers']];
    }

    /**
     * When each teacher is IN THE CENTRE on a weekday, as the first and last period they teach.
     *
     * This is the centre's own rule for a cultural day, in their words: "el programa preasigna al
     * profesorado respetando su horario habitual (si su docencia empieza a las 9:20, su participación
     * empieza en torno a esa hora)". Somebody whose teaching starts at third period is not in the
     * building at first, whatever the alternative timetable says.
     *
     * Only teaching cells count. A guardia slot says the person is on call, not that they came in early.
     *
     * @param AcademicYear $year    the course whose timetable to read
     * @param Weekday      $weekday the weekday
     *
     * @return array<int, array{from: int, to: int}> teacher id → first and last period they teach
     */
    public function teachingDayBounds(AcademicYear $year, Weekday $weekday): array
    {
        /** @var list<array{teacherId: int|string, firstSlot: int|string, lastSlot: int|string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('IDENTITY(s.teacher) AS teacherId', 'MIN(s.slotIndex) AS firstSlot', 'MAX(s.slotIndex) AS lastSlot')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.weekday = :weekday')
            ->andWhere('s.kind = :lective')
            ->setParameter('year', $year)
            ->setParameter('weekday', $weekday)
            ->setParameter('lective', ScheduleActivityKind::LECTIVE)
            ->groupBy('s.teacher')
            ->getQuery()
            ->getResult();

        $bounds = [];
        foreach ($rows as $row) {
            $bounds[(int) $row['teacherId']] = ['from' => (int) $row['firstSlot'], 'to' => (int) $row['lastSlot']];
        }

        return $bounds;
    }

    /**
     * The groups a course's timetable knows about, alphabetically — what a plan offers when it asks
     * "whose timetable does this replace?". Offered as a list rather than typed in because a group name
     * that does not match the timetable exactly would silently replace nobody.
     *
     * @param AcademicYear $year the course whose timetable to read
     *
     * @return list<string> the group names
     */
    public function distinctGroupNames(AcademicYear $year): array
    {
        /** @var list<array{groupName: string}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('DISTINCT s.groupName AS groupName')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.groupName IS NOT NULL')
            ->andWhere("TRIM(s.groupName) <> ''")
            ->setParameter('year', $year)
            ->orderBy('s.groupName', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $r): string => $r['groupName'], $rows);
    }

    /**
     * How many timetable cells reference a space. Guards the catalogue's delete action: a room the
     * timetable uses may only be deactivated, never removed, or its cells would silently stop counting
     * as occupied.
     *
     * @param Room $room the space to count uses of
     *
     * @return int the number of cells pointing at it
     */
    public function countByRoom(Room $room): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.room = :room')
            ->setParameter('room', $room)
            ->getQuery()
            ->getSingleScalarResult();
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
