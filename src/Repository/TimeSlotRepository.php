<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\TimeSlot;
use App\Enum\TimeSlotKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TimeSlot>
 */
class TimeSlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimeSlot::class);
    }

    /**
     * The course's whole marco horario, earliest period first.
     *
     * @param AcademicYear $year the course whose frame to read
     *
     * @return TimeSlot[] every period of the day
     */
    public function findByYear(AcademicYear $year): array
    {
        return $this->frameQuery($year)->getQuery()->getResult();
    }

    /**
     * The course's break periods, earliest first — the recreos the duty rota covers. Two at the centre
     * (the long morning one and the short one before the last period), but read from the data, never
     * assumed: a course whose timetable has not been imported yet simply has none.
     *
     * @param AcademicYear|null $year the course whose breaks to read, or null when no course applies
     *
     * @return TimeSlot[] the break periods, earliest first
     */
    public function findBreaksByYear(?AcademicYear $year): array
    {
        if (null === $year) {
            return [];
        }

        return $this->frameQuery($year)
            ->andWhere('t.kind = :break')
            ->setParameter('break', TimeSlotKind::BREAK_TIME)
            ->getQuery()
            ->getResult();
    }

    /**
     * The course's teaching periods, earliest first — the ones a guardia can be placed in. Six at the
     * centre, but read from the data rather than assumed: a course whose timetable has not been imported
     * yet has none, and the caller has to say so instead of quietly computing a balance out of zero.
     *
     * @param AcademicYear|null $year the course whose teaching periods to read, or null when none applies
     *
     * @return TimeSlot[] the teaching periods, earliest first
     */
    public function findLectiveByYear(?AcademicYear $year): array
    {
        if (null === $year) {
            return [];
        }

        return $this->frameQuery($year)
            ->andWhere('t.kind = :lective')
            ->setParameter('lective', TimeSlotKind::LECTIVE)
            ->getQuery()
            ->getResult();
    }

    /**
     * The course's teaching periods as index → times, falling back to the most recent course that HAS a
     * frame when this one does not. For screens that have to name an hour of the day before the new
     * course's timetable exists — {@see \App\Controller\BookingController} above all.
     *
     * The fallback is the point. A course's frame arrives with its Peñalara import, which at this centre
     * lands weeks INTO September, so between the 1st and the import the new course has no periods at all.
     * The obvious stand-in — "offer six consecutive hours" — is wrong here in a way that hides: the
     * centre's day has EIGHT indexes of which two are recreos (3 and 6), so the sixth consecutive index
     * is not the sixth lesson, and every booking taken before the import would come out an hour off the
     * moment the real frame landed. Borrowing last year's frame gets the indexes right, because the index
     * is what is stored: only the clock times are shown, and those follow whatever frame is current, so a
     * booking survives a timetable that moves the bell.
     *
     * Not silent: {@code borrowedFrom} carries the course the frame came from precisely so the screen can
     * say whose hours these are, and null when they are the course's own.
     *
     * @param AcademicYear|null $year the course whose periods to name, or null when no course applies
     *
     * @return array{slots: array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}>, borrowedFrom: string|null}
     *                                                                                                    the periods by index, and the course they were borrowed from
     */
    public function lectiveTimesWithFallback(?AcademicYear $year): array
    {
        $own = self::timesBySlot($this->findLectiveByYear($year));
        if ([] !== $own) {
            return ['slots' => $own, 'borrowedFrom' => null];
        }

        $latest = $this->mostRecentSchoolYearWithLectiveFrame();
        if (null === $latest) {
            return ['slots' => [], 'borrowedFrom' => null];
        }

        return ['slots' => self::timesBySlot($this->findLectiveBySchoolYear($latest)), 'borrowedFrom' => $latest];
    }

    /**
     * Replaces a course's whole marco horario with the given periods, in one transaction so a concurrent
     * read never sees a half-written day. Called by the importer: the frame is derived data, so it is
     * rebuilt rather than merged — there is no hand-edited version of it to preserve.
     *
     * @param AcademicYear   $year  the course whose frame is replaced
     * @param list<TimeSlot> $slots the fresh periods to persist
     */
    public function replaceForYear(AcademicYear $year, array $slots): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(function () use ($em, $year, $slots): void {
            $this->createQueryBuilder('t')
                ->delete()
                ->where('t.academicYear = :year')
                ->setParameter('year', $year)
                ->getQuery()
                ->execute();
            foreach ($slots as $slot) {
                $em->persist($slot);
            }
            $em->flush();
        });
    }

    /**
     * The label of the latest course that has any teaching period defined, or null when no course does.
     *
     * Ordered by the label (the "2025-2026" string) rather than by a date: that format sorts
     * lexicographically in the same order it sorts chronologically, and it is the column the unique key
     * is on, so there is nothing to join or derive.
     *
     * @return string|null the school year label, e.g. "2025-2026"
     */
    private function mostRecentSchoolYearWithLectiveFrame(): ?string
    {
        /** @var string|null $latest */
        $latest = $this->createQueryBuilder('t')
            ->select('y.schoolYear')
            ->join('t.academicYear', 'y')
            ->andWhere('t.kind = :lective')
            ->setParameter('lective', TimeSlotKind::LECTIVE)
            ->orderBy('y.schoolYear', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SCALAR_COLUMN);

        return $latest;
    }

    /**
     * A course's teaching periods looked up by its label, earliest first.
     *
     * @param string $schoolYear the course label, e.g. "2025-2026"
     *
     * @return TimeSlot[] the teaching periods, earliest first
     */
    private function findLectiveBySchoolYear(string $schoolYear): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.academicYear', 'y')
            ->andWhere('y.schoolYear = :schoolYear')
            ->andWhere('t.kind = :lective')
            ->setParameter('schoolYear', $schoolYear)
            ->setParameter('lective', TimeSlotKind::LECTIVE)
            ->orderBy('t.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Reshapes periods into index → times, which is the shape every screen that resolves an hour from a
     * stored slot index needs.
     *
     * @param TimeSlot[] $slots the periods
     *
     * @return array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> the periods by index
     */
    private static function timesBySlot(array $slots): array
    {
        $times = [];
        foreach ($slots as $slot) {
            $times[$slot->getSlotIndex()] = ['startsAt' => $slot->getStartsAt(), 'endsAt' => $slot->getEndsAt()];
        }

        return $times;
    }

    /**
     * Base query for a course's frame, ordered as the day runs.
     *
     * @param AcademicYear $year the course whose frame to read
     *
     * @return \Doctrine\ORM\QueryBuilder the ordered query
     */
    private function frameQuery(AcademicYear $year): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.academicYear = :year')
            ->setParameter('year', $year)
            ->orderBy('t.startsAt', 'ASC');
    }
}
