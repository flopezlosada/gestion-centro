<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\GuardiaTaskBankItem;
use App\Enum\EducationLevel;
use App\Util\GroupCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuardiaTaskBankItem>
 */
class GuardiaTaskBankItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuardiaTaskBankItem::class);
    }

    /**
     * The bank of a course as a browsable list, newest first within each level, optionally narrowed to
     * a level, a subject, a group (its section letters) or a department. Retired tasks are left out
     * unless asked for (the department managing its own bank wants to see them; the teacher picking a
     * task for a group does not).
     *
     * @param AcademicYear $year           the course whose bank to read
     * @param EducationLevel|null $level          only tasks for this level
     * @param string|null         $subject        only tasks of this subject (exact, as the timetable spells it)
     * @param string|null         $groupName      only tasks whose section letters fit this group
     * @param int|null            $departmentId   only tasks contributed by this department
     * @param bool                $includeRetired whether to also list the retired ones
     *
     * @return list<GuardiaTaskBankItem> the matching tasks
     */
    public function findFiltered(AcademicYear $year, ?EducationLevel $level = null, ?string $subject = null, ?string $groupName = null, ?int $departmentId = null, bool $includeRetired = false): array
    {
        $qb = $this->createQueryBuilder('i')
            ->addSelect('d')
            ->join('i.department', 'd')
            ->addOrderBy('i.subject', 'ASC')
            ->addOrderBy('i.createdAt', 'DESC');

        $this->applyFilters($qb, $year, $level, $subject, $departmentId, $includeRetired);

        /** @var list<GuardiaTaskBankItem> $rows */
        $rows = $qb->getQuery()->getResult();

        // Por nivel de enseñanza (ESO → DIV → BACH → GB), no por el valor del enum, que ordenado
        // alfabéticamente pondría Bachillerato el primero y no casaría con el desplegable.
        $rank = array_flip(array_map(static fn (EducationLevel $l): string => $l->value, EducationLevel::inDisplayOrder()));
        usort($rows, static fn (GuardiaTaskBankItem $a, GuardiaTaskBankItem $b): int => $rank[$a->getLevel()->value] <=> $rank[$b->getLevel()->value]);

        return self::fittingGroup($rows, $groupName);
    }

    /**
     * Picks one task at random for a class — what the covering teacher gets when the absent one left
     * nothing. The level and the subject are both required: the centre's rule is that the group works
     * on the subject it was going to have, so a random pick can never hand out a task of another one.
     *
     * "At random" among the LEAST used ones: with a fresh bank every task is equally likely, and as the
     * course goes on the pick spreads over the bank instead of landing on the same sheet forever (and a
     * class does not get the reading its neighbours already did last week).
     *
     * @param AcademicYear   $year      the course to pick from
     * @param EducationLevel $level     the level of the class
     * @param string         $subject   the subject the class was going to have
     * @param string|null    $groupName    the group being covered, to respect any section restriction
     * @param int|null       $departmentId narrow to one department, when the screen was filtered by it
     *
     * @return GuardiaTaskBankItem|null the chosen task, or null when the bank has none for that class
     */
    public function pickRandom(AcademicYear $year, EducationLevel $level, string $subject, ?string $groupName = null, ?int $departmentId = null): ?GuardiaTaskBankItem
    {
        $qb = $this->createQueryBuilder('i')->orderBy('i.timesUsed', 'ASC');
        $this->applyFilters($qb, $year, $level, $subject, $departmentId, false);

        /** @var list<GuardiaTaskBankItem> $rows */
        $rows = self::fittingGroup($qb->getQuery()->getResult(), $groupName);
        if ([] === $rows) {
            return null;
        }

        // Every task tied on the lowest use count is a candidate; one of them is drawn.
        $fewest = $rows[0]->getTimesUsed();
        $candidates = array_values(array_filter($rows, static fn (GuardiaTaskBankItem $i): bool => $i->getTimesUsed() === $fewest));

        return $candidates[random_int(0, \count($candidates) - 1)];
    }

    /**
     * Records one more use of a task, atomically. Two teachers covering the same period press "Del
     * banco" at the same time; a read-modify-write in PHP would lose one of the increments and the
     * "least used first" split would drift, so the database does the arithmetic.
     *
     * @param GuardiaTaskBankItem $item the task just handed to a group
     */
    public function recordUse(GuardiaTaskBankItem $item): void
    {
        $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\GuardiaTaskBankItem i SET i.timesUsed = i.timesUsed + 1 WHERE i.id = :id'
        )->setParameter('id', $item->getId())->execute();
    }

    /**
     * How many active tasks the course's bank holds per level, to show its coverage at a glance (and
     * make an empty level obvious to the departments).
     *
     * @param AcademicYear $year the course
     *
     * @return array<string, int> level value → number of active tasks
     */
    public function countActiveByLevel(AcademicYear $year): array
    {
        /** @var list<array{level: EducationLevel, total: int}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.level AS level', 'COUNT(i.id) AS total')
            ->where('i.active = true')
            ->andWhere('i.academicYear = :year')
            ->setParameter('year', $year)
            ->groupBy('i.level')
            ->getQuery()
            ->getResult();

        $byLevel = [];
        foreach ($rows as $row) {
            // A scalar select of an enumType column hydrates the enum itself (unlike an aggregate).
            $byLevel[$row['level']->value] = (int) $row['total'];
        }

        return $byLevel;
    }

    /**
     * Keeps the tasks whose section restriction fits the group being covered. Done in PHP, not in SQL:
     * the letters are a short list on a handful of rows, and the rule ({@see GroupCode::sectionsMatch()})
     * is the same one the screens explain, in one place.
     *
     * @param list<GuardiaTaskBankItem> $items     the candidate tasks
     * @param string|null               $groupName the group being covered, or null to not narrow
     *
     * @return list<GuardiaTaskBankItem> the fitting tasks
     */
    private static function fittingGroup(array $items, ?string $groupName): array
    {
        if (null === $groupName || '' === trim($groupName)) {
            return $items;
        }

        $classSections = GroupCode::sections($groupName);

        return array_values(array_filter(
            $items,
            static fn (GuardiaTaskBankItem $i): bool => GroupCode::sectionsMatch($i->getSections(), $classSections),
        ));
    }

    /**
     * Applies the shared narrowing of both reads (listing and random pick) so they can never drift
     * apart: what the teacher sees listed is exactly what the dice could have rolled.
     *
     * @param QueryBuilder        $qb             the query being built, rooted on alias "i"
     * @param AcademicYear        $year           the course whose bank to read
     * @param EducationLevel|null $level          only tasks for this level
     * @param string|null         $subject        only tasks of this subject
     * @param int|null            $departmentId   only tasks of this department
     * @param bool                $includeRetired whether retired tasks stay in
     */
    private function applyFilters(QueryBuilder $qb, AcademicYear $year, ?EducationLevel $level, ?string $subject, ?int $departmentId, bool $includeRetired): void
    {
        $qb->andWhere('i.academicYear = :year')->setParameter('year', $year);

        if (!$includeRetired) {
            $qb->andWhere('i.active = true');
        }
        if (null !== $level) {
            $qb->andWhere('i.level = :level')->setParameter('level', $level);
        }
        if (null !== $subject && '' !== trim($subject)) {
            $qb->andWhere('i.subject = :subject')->setParameter('subject', trim($subject));
        }
        if (null !== $departmentId) {
            $qb->andWhere('i.department = :department')->setParameter('department', $departmentId);
        }
    }
}
