<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\Substitution;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Substitution>
 */
class SubstitutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Substitution::class);
    }

    /**
     * Las sustituciones EN VIGOR de un curso, las dos personas cargadas de una vez porque toda pantalla
     * que las lista las nombra a las dos.
     *
     * @param AcademicYear $year el curso
     *
     * @return Substitution[] las abiertas, por nombre de la persona sustituida
     */
    public function findOpenFor(AcademicYear $year): array
    {
        return $this->openQuery()
            ->addSelect('substituted', 'substitute')
            ->andWhere('s.academicYear = :year')
            ->setParameter('year', $year)
            ->orderBy('substituted.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Las sustituciones YA CERRADAS de un curso, la última primero — el historial que va debajo de las
     * abiertas en la pantalla.
     *
     * Devuelto aparte de {@see findOpenFor()} y no como una lista ordenada por estado: DQL no ordena por
     * una expresión CASE sin sacarla al SELECT, y sacarla convertiría el resultado en filas mixtas de
     * entidad y escalar. Dos preguntas distintas, dos consultas.
     *
     * @param AcademicYear $year the course
     *
     * @return Substitution[] las cerradas, la más reciente primero
     */
    public function findClosedFor(AcademicYear $year): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('substituted', 'substitute')
            ->join('s.substitutedTeacher', 'substituted')
            ->join('s.substitute', 'substitute')
            ->andWhere('s.academicYear = :year')
            ->andWhere('s.endedOn IS NOT NULL')
            ->setParameter('year', $year)
            ->orderBy('s.endedOn', 'DESC')
            ->addOrderBy('s.startedOn', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * La sustitución en vigor en la que una persona participa, del lado que sea: sustituyendo o
     * sustituida. Una sola pregunta y no dos porque la respuesta se usa para lo mismo en los dos casos
     * — impedir abrir una sustitución encima de otra, y avisar en pantalla de que esa persona está
     * dentro de una.
     *
     * Sin filtro de curso a propósito: nadie puede estar sustituido en un curso y libre en otro al mismo
     * tiempo, y una sustitución abierta que se quedó de un curso viejo es precisamente lo que hay que
     * ver, no lo que hay que esconder.
     *
     * @param User $person la persona por la que se pregunta
     *
     * @return Substitution|null la sustitución abierta que le afecta, o null si no hay ninguna
     */
    public function findOpenInvolving(User $person): ?Substitution
    {
        return $this->openQuery()
            ->andWhere('s.substitutedTeacher = :person OR s.substitute = :person')
            ->setParameter('person', $person)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Las sustituciones en vigor de TODO el sistema, para la aportación al contador de guardias
     * ({@see GuardiaCoverRepository}).
     *
     * Sin filtro de curso porque el contador tampoco lo tiene: cuenta covers, que se llevan por fecha y
     * no por curso. Acotar aquí al curso vigente dejaría a quien sustituye en cero durante los días de
     * septiembre en que el curso nuevo todavía no está dado de alta.
     *
     * @return Substitution[] las abiertas, las dos personas cargadas
     */
    public function findAllOpen(): array
    {
        return $this->openQuery()
            ->addSelect('substituted', 'substitute')
            ->getQuery()
            ->getResult();
    }

    /**
     * El esqueleto compartido: las sustituciones sin cerrar, con las dos personas unidas para que quien
     * quiera seleccionarlas no repita el join.
     *
     * @return QueryBuilder el builder, alias {@code s}
     */
    private function openQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->join('s.substitutedTeacher', 'substituted')
            ->join('s.substitute', 'substitute')
            ->andWhere('s.endedOn IS NULL');
    }
}
