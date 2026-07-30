<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CopyRequest;
use App\Entity\GuardiaCover;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CopyRequest>
 */
class CopyRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CopyRequest::class);
    }

    /**
     * The copy-room orders to show on the listing, newest first: everyone's for whoever coordinates
     * guardias, only their own for anyone else — an order carries what a colleague is doing that hour,
     * so it is not everybody's business.
     *
     * @param User|null $requestedBy limit to this person's orders, or null for everyone's
     * @param int       $limit       how many rows at most
     *
     * @return list<CopyRequest> the orders, newest first
     */
    public function findRecent(?User $requestedBy = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('r')
            // La coordinación ve el nombre de quien pidió cada encargo: sin el join serían hasta 100
            // consultas para pintar la lista.
            ->addSelect('u')
            ->leftJoin('r.requestedBy', 'u')
            ->orderBy('r.requestedAt', 'DESC')
            ->setMaxResults($limit);

        if (null !== $requestedBy) {
            $qb->andWhere('r.requestedBy = :user')->setParameter('user', $requestedBy);
        }

        /** @var list<CopyRequest> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * The orders already placed for one guardia, oldest first — so the screen can say "ya se pidieron
     * 25 copias" instead of letting someone order the same thing twice.
     *
     * @param GuardiaCover $cover the parte line
     *
     * @return list<CopyRequest> its orders, in the order they were placed
     */
    public function findForCover(GuardiaCover $cover): array
    {
        /** @var list<CopyRequest> $rows */
        $rows = $this->createQueryBuilder('r')
            ->where('r.cover = :cover')
            ->setParameter('cover', $cover)
            ->orderBy('r.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
