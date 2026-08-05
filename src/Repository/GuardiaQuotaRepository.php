<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\GuardiaQuota;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuardiaQuota>
 */
class GuardiaQuotaRepository extends ServiceEntityRepository
{
    /**
     * @param SubstitutionRepository $substitutions las sustituciones en vigor, para que quien cubre una
     *                                             baja larga trabaje con el cupo de la persona a la que
     *                                             sustituye ({@see self::findEffectiveByTeacher()})
     */
    public function __construct(
        ManagerRegistry $registry,
        private readonly SubstitutionRepository $substitutions,
    ) {
        parent::__construct($registry, GuardiaQuota::class);
    }

    /**
     * The course's quotas keyed by teacher id, which is how every caller wants them: the screen draws a
     * row per teacher and needs to find that teacher's quota, and a teacher with no row yet simply has
     * no key.
     *
     * The teacher is joined in the same read. Without it, rendering a table of seventy rows would fire
     * seventy extra queries to print the names.
     *
     * @param AcademicYear $year the course whose quotas to read
     *
     * @return array<int, GuardiaQuota> the quotas, keyed by teacher id
     */
    public function findByYearKeyedByTeacher(AcademicYear $year): array
    {
        /** @var GuardiaQuota[] $rows */
        $rows = $this->createQueryBuilder('q')
            ->addSelect('t')
            ->join('q.teacher', 't')
            ->andWhere('q.academicYear = :year')
            ->setParameter('year', $year)
            ->getQuery()
            ->getResult();

        $byTeacher = [];
        foreach ($rows as $row) {
            $byTeacher[(int) $row->getTeacher()->getId()] = $row;
        }

        return $byTeacher;
    }

    /**
     * El cupo que RIGE para cada persona con horario en el curso: el suyo si lo tiene, y si está
     * cubriendo una baja larga y no tiene, el de la persona a la que sustituye.
     *
     * La herencia hace falta porque el cupo es del PUESTO, no de quien lo ocupa esa semana. Durante una
     * baja quien tiene horario —y por tanto quien entra en el cuadrante— es quien sustituye, y esa
     * persona llega sin fila de cupo: sin heredar, saldría con cero, que en este modelo significa
     * "exenta" ({@see GuardiaQuota}), y desaparecería del reparto entero. Se hereda, en vez de copiar la
     * fila al dar de alta la sustitución, por lo mismo que el contador de guardias: una copia es un
     * segundo sitio donde el número puede discrepar.
     *
     * Devuelve CIFRAS, no entidades, y eso es la mitad del asunto. El mapa de entidades
     * ({@see findByYearKeyedByTeacher()}) es el que usa la pantalla para guardar, y si la herencia
     * entrara ahí, teclear el cupo de quien sustituye escribiría sobre la fila de la persona sustituida
     * —la que el motor usará cuando vuelva— sin que nadie lo viera. Aquí no hay nada sobre lo que
     * escribir.
     *
     * Quien hereda viene marcado con el nombre de quien le cede el cupo, y no por adorno: la pantalla lo
     * usa para pintar esa fila SIN casillas. Con casillas, el navegador mandaría la cifra heredada en
     * cada envío de la tabla —se hubiera tocado o no— y le crearía una fila propia que a partir de ahí
     * dejaría de seguir al cupo del puesto.
     *
     * @param AcademicYear $year el curso
     *
     * @return array<int, array{lective: int, break: int, inheritedFrom: string|null}> id de docente → su cupo en vigor
     */
    public function findEffectiveByTeacher(AcademicYear $year): array
    {
        $own = $this->findByYearKeyedByTeacher($year);

        $effective = [];
        foreach ($own as $id => $quota) {
            $effective[$id] = ['lective' => $quota->getLectiveDuties(), 'break' => $quota->getBreakDuties(), 'inheritedFrom' => null];
        }

        foreach ($this->substitutions->findOpenFor($year) as $substitution) {
            $substituteId = (int) $substitution->getSubstitute()->getId();
            $substitutedId = (int) $substitution->getSubstitutedTeacher()->getId();
            // Un cupo propio gana al heredado: si alguien se ha molestado en teclearlo para quien
            // sustituye, es una decisión posterior y más concreta que la del puesto.
            if (!isset($effective[$substituteId]) && isset($effective[$substitutedId])) {
                $effective[$substituteId] = [
                    ...$effective[$substitutedId],
                    'inheritedFrom' => $substitution->getSubstitutedTeacher()->getFullName(),
                ];
            }
        }

        return $effective;
    }
}
