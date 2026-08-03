<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\Department;
use App\Entity\User;
use App\Service\TaskGenerator;
use App\Support\TaskStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * Tasks of a course, earliest deadline first.
     *
     * @param string $schoolYear the course in "YYYY-YYYY" form
     *
     * @return Task[] the tasks of that course
     */
    public function findBySchoolYear(string $schoolYear): array
    {
        // Fetch-join the associations shown in the list/plan (and read by the visibility scope) to
        // avoid an N+1 per row — including the responsibility (role + department) and the delegatee,
        // now that the RESPONSABLE column resolves to the current responsible (delegatee if delegated).
        return $this->createQueryBuilder('t')
            ->leftJoin('t.unit', 'unit')->addSelect('unit')
            ->leftJoin('t.assignedUser', 'assignedUser')->addSelect('assignedUser')
            ->leftJoin('t.delegatedTo', 'delegatedTo')->addSelect('delegatedTo')
            ->leftJoin('t.responsibility', 'resp')->addSelect('resp')
            ->leftJoin('resp.role', 'respRole')->addSelect('respRole')
            ->leftJoin('resp.unit', 'respUnit')->addSelect('respUnit')
            ->andWhere('t.schoolYear = :year')
            ->setParameter('year', $schoolYear)
            ->orderBy('t.dueDate', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The distinct school years that have at least one task, most recent first. Feeds the course
     * selector so the plan and the panel can look at past courses (the histórico).
     *
     * @return list<string> the school years in "YYYY-YYYY" form, newest first
     */
    public function schoolYearsWithTasks(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('DISTINCT t.schoolYear AS y')
            ->orderBy('t.schoolYear', 'DESC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): string => (string) $row['y'], $rows);
    }

    /**
     * The tasks a given person is the concrete assignee of, for a course, earliest deadline first.
     * Powers the "sus tareas" block on a user's admin profile. Fetch-joins the associations the list
     * renders (unit + responsibility role) to avoid an N+1 per row.
     *
     * @param User   $user       the assignee
     * @param string $schoolYear the course in "YYYY-YYYY" form
     *
     * @return Task[] the tasks assigned to the user in that course
     */
    public function findAssignedTo(User $user, string $schoolYear): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.unit', 'unit')->addSelect('unit')
            ->leftJoin('t.responsibility', 'resp')->addSelect('resp')
            ->leftJoin('resp.role', 'respRole')->addSelect('respRole')
            ->andWhere('t.assignedUser = :user')->setParameter('user', $user)
            ->andWhere('t.schoolYear = :year')->setParameter('year', $schoolYear)
            ->orderBy('t.dueDate', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tasks whose deadline falls within an inclusive date range, earliest deadline first. Used by
     * the monthly calendar to fill a visible month grid.
     *
     * @param \DateTimeImmutable $from the first day of the range (inclusive)
     * @param \DateTimeImmutable $to   the last day of the range (inclusive)
     *
     * @return Task[] the tasks due within the range
     */
    public function findDueBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // Fetch-join the associations shown on each day cell (and read by the visibility scope) to
        // avoid an N+1 per row — including the responsibility (role + department).
        return $this->createQueryBuilder('t')
            ->leftJoin('t.unit', 'unit')->addSelect('unit')
            ->leftJoin('t.assignedUser', 'assignedUser')->addSelect('assignedUser')
            ->leftJoin('t.responsibility', 'resp')->addSelect('resp')
            ->leftJoin('resp.role', 'respRole')->addSelect('respRole')
            ->leftJoin('resp.unit', 'respUnit')->addSelect('respUnit')
            ->andWhere('t.dueDate BETWEEN :from AND :to')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('t.dueDate', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A person's agenda for a course: the tasks assigned to them directly or to any of their roles,
     * earliest deadline first.
     *
     * @param User   $user       the person
     * @param string $schoolYear the course in "YYYY-YYYY" form
     *
     * @return Task[] the person's tasks that course
     */
    public function findAgendaFor(User $user, string $schoolYear): array
    {
        $roleIds = array_values(array_filter(
            $user->getAssignedRoles()->map(static fn (Role $role): ?int => $role->getId())->toArray(),
        ));

        // Las canceladas se quedan fuera: no son ni pendientes ni un logro, así que no ocupan la agenda.
        // Las finalizadas sí se traen: caen en el bucket "Hechas" (ver AgendaEntry::fromTask).
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.responsibility', 'resp')
            ->andWhere('t.schoolYear = :year')
            ->andWhere('t.status != :cancelled')
            ->setParameter('year', $schoolYear)
            ->setParameter('cancelled', TaskStatus::CANCELLED)
            ->orderBy('t.dueDate', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->setParameter('user', $user);

        // La agenda es "lo MÍO que hacer" = el responsable ACTUAL. Si la tarea está delegada, el
        // responsable es SOLO el delegado: una que yo delegué hacia abajo deja de ser mi pendiente y
        // sale de mi agenda; una delegada A mí entra. Sin delegación manda el asignado concreto y, si
        // no hay ninguno, quien tenga la responsabilidad estructural.
        //
        // Es {@see \App\Entity\Task::isOwnedBy()} llevado a SQL, PASO POR PASO y no "parecido": antes
        // esta consulta miraba el rol legacy sin exigir `assignedUser IS NULL`, así que una tarea
        // reasignada a otra persona seguía apareciendo en la agenda de todo el que tuviera el rol,
        // mientras que la ficha de esa misma tarea decía que no era suya. La condición del
        // departamento reproduce el filtro de TaskResponsibility::holders(): un rol de departamento
        // solo es tuyo en el TUYO.
        $ownRole = '(t.assignedUser IS NULL AND resp.role IN (:roles) AND (resp.unit IS NULL OR resp.unit = :unit))';
        if ([] === $roleIds) {
            $qb->andWhere('t.delegatedTo = :user OR (t.delegatedTo IS NULL AND t.assignedUser = :user)');
        } else {
            $qb->andWhere("t.delegatedTo = :user OR (t.delegatedTo IS NULL AND (t.assignedUser = :user OR {$ownRole}))")
                ->setParameter('roles', $roleIds)
                ->setParameter('unit', $user->getUnit());
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Open (not yet validated) tasks whose deadline falls on a given day. Used by the reminder
     * engine to find what is due in N days.
     *
     * @param \DateTimeImmutable $day       the deadline day to match
     * @param list<string>       $openPlaces the statuses considered still open
     *
     * @return Task[] the matching open tasks
     */
    public function findOpenDueOn(\DateTimeImmutable $day, array $openPlaces): array
    {
        // Fetch-join the associations the reminder engine reads per task, to avoid an N+1 — including
        // the responsibility and its role, which is where an unassigned task's recipients now come from
        // ({@see \App\Service\TaskReminderNotifier}); without it the nightly sweep would fire two extra
        // queries for every task nobody is concretely on the hook for.
        return $this->createQueryBuilder('t')
            ->leftJoin('t.assignedUser', 'assignedUser')->addSelect('assignedUser')
            ->leftJoin('t.unit', 'unit')->addSelect('unit')
            ->leftJoin('t.responsibility', 'resp')->addSelect('resp')
            ->leftJoin('resp.role', 'respRole')->addSelect('respRole')
            ->leftJoin('resp.unit', 'respUnit')->addSelect('respUnit')
            ->andWhere('t.dueDate = :day')
            ->andWhere('t.status IN (:open)')
            ->setParameter('day', $day->format('Y-m-d'))
            ->setParameter('open', $openPlaces)
            ->orderBy('t.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Open (not yet validated) tasks of a course whose structural responsibility is a given role in a
     * given scope. Used to hand over a ranked role's current tasks to a new holder when the post
     * changes (jefe de departamento, jefatura de estudios, dirección…). A null unit matches centre-wide
     * responsibilities (which store no department).
     *
     * @param Role      $role       the responsibility role
     * @param Department|null $unit       the department the responsibility is scoped to, or null for centre-wide
     * @param string    $schoolYear the course in "YYYY-YYYY" form
     *
     * @return Task[] the matching open tasks
     */
    public function findOpenByResponsibility(Role $role, ?Department $unit, string $schoolYear): array
    {
        // "Abierta" = ni finalizada ni cancelada: las cerradas (de cualquier forma) son histórico y no
        // se reasignan en un relevo de jefatura.
        $qb = $this->createQueryBuilder('t')
            ->join('t.responsibility', 'resp')
            ->andWhere('resp.role = :role')
            ->andWhere('t.schoolYear = :year')
            ->andWhere('t.status NOT IN (:closed)')
            ->setParameter('role', $role)
            ->setParameter('year', $schoolYear)
            ->setParameter('closed', TaskStatus::CLOSED);

        if (null === $unit) {
            $qb->andWhere('resp.unit IS NULL');
        } else {
            $qb->andWhere('resp.unit = :unit')->setParameter('unit', $unit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * The set of keys already generated for a course, so the yearly generation can skip re-creating a
     * task it already produced (idempotent re-runs). One query, no per-item lookup.
     *
     * The DEPARTMENT is part of the key ({@see \App\Service\TaskGenerator::keyFor()}), because one
     * template and one date legitimately produce one task per department. Keyed by template and date
     * alone, a re-run would find the first department's task and skip the other twenty as if they had
     * been generated — which is how twenty departments silently lose their memoria.
     *
     * @param string $schoolYear the course in "YYYY-YYYY" form
     *
     * @return array<string, true> a lookup set keyed by "templateId|dueDate|departmentId"
     */
    public function generatedKeysFor(string $schoolYear): array
    {
        // The department is read from the responsibility, which is where the generator puts it, and
        // NOT from t.unit: the two are mirrored today, but the responsibility is the one the model
        // treats as authoritative, so a task edited later cannot drift out of its own key.
        /** @var list<array{tpl: int, due: \DateTimeImmutable, unit: int|null}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->leftJoin('t.responsibility', 'resp')
            ->select('IDENTITY(t.template) AS tpl', 't.dueDate AS due', 'IDENTITY(resp.unit) AS unit')
            ->andWhere('t.schoolYear = :year')
            ->andWhere('t.template IS NOT NULL')
            ->setParameter('year', $schoolYear)
            ->getQuery()
            ->getArrayResult();

        $keys = [];
        foreach ($rows as $row) {
            $keys[TaskGenerator::keyFor($row['tpl'], $row['due'], $row['unit'])] = true;
        }

        return $keys;
    }
}
