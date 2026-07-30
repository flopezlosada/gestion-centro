<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\TimeSlot;
use App\Enum\TimeSlotKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
