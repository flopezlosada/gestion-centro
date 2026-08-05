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
     * The spaces the staff may BOOK: in use and marked reservable — what the /reservas form offers.
     *
     * A query of its own rather than filtering {@see findActive()} in the controller, so the rule lives in
     * one place: the day somebody adds a second booking surface, it cannot forget half the condition and
     * quietly offer the gym.
     *
     * @return Room[] the bookable spaces, code ascending
     */
    public function findReservable(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.active = true')
            ->andWhere('r.reservable = true')
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
