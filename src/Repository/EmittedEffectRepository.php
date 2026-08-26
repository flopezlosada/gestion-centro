<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmittedEffect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Sin métodos propios a propósito: quien decide si un efecto ya se emitió no
 * consulta, INSERTA y deja que el índice único responda
 * ({@see \App\Service\Cron\EffectLedger}). Un `findOneBy` previo sería
 * exactamente el `if` que pierde la carrera.
 *
 * @extends ServiceEntityRepository<EmittedEffect>
 */
class EmittedEffectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmittedEffect::class);
    }
}
