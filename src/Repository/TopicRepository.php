<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Topic;
use App\Enum\EducationLevel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Topic>
 */
class TopicRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Topic::class);
    }

    /**
     * The shared topic list of a subject and level, in teaching order, retired ones included — the
     * caller decides whether to offer them (never) or to match a typed name against them (always, so a
     * retired name is not created again as a new topic).
     *
     * @param string              $subject the subject, as the timetable spells it
     * @param EducationLevel|null $level   the level, or null for groups with no recognised level
     *
     * @return list<Topic> the topics, in order
     */
    public function findListFor(string $subject, ?EducationLevel $level): array
    {
        return $this->scope($subject, $level)
            ->orderBy('t.position', 'ASC')->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The topics of one list, by subject and level. A null level is its own list, compared with IS NULL:
     * "= NULL" would match nothing.
     *
     * @param string              $subject the subject
     * @param EducationLevel|null $level   the level, or null
     *
     * @return QueryBuilder the query so far
     */
    private function scope(string $subject, ?EducationLevel $level): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')->andWhere('t.subject = :subject')->setParameter('subject', $subject);

        return null === $level
            ? $qb->andWhere('t.level IS NULL')
            : $qb->andWhere('t.level = :level')->setParameter('level', $level);
    }
}
