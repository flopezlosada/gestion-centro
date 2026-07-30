<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Room;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Room>
 */
class RoomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Room::class);
    }

    /**
     * Every space, code ascending — the catalogue listing. Includes the deactivated ones: the screen
     * that manages the catalogue is the one place where a retired room still has to be visible.
     *
     * @return Room[] the spaces, code ascending
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The spaces in use, code ascending — what every screen other than the catalogue should offer.
     *
     * @return Room[] the active spaces, code ascending
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.active = true')
            ->orderBy('r.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every space keyed by its (normalised) code, in one query. What the synchroniser compares the
     * timetable against, so discovering rooms never costs a query per room name.
     *
     * @return array<string, Room> code → room
     */
    public function indexedByCode(): array
    {
        $byCode = [];
        foreach ($this->findAll() as $room) {
            $byCode[$room->getCode()] = $room;
        }

        return $byCode;
    }

    /**
     * Looks a space up by code, normalising first so "bibl", " BIBL " and "BIBL" all find the same card.
     *
     * @param string $code the raw code
     *
     * @return Room|null the space, or null if the centre has no card for it
     */
    public function findByCode(string $code): ?Room
    {
        return $this->findOneBy(['code' => Room::normaliseCode($code)]);
    }
}
