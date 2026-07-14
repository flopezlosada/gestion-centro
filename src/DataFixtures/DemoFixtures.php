<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\AcademicYear;
use App\Entity\NonLectiveDay;
use App\Entity\Notification;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\TaskTemplate;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\TaskType;
use App\Service\SchoolCalendar;
use App\Util\SchoolYear;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Sample data to see the app working locally: a small org chart (management → head of studies →
 * maths), a few people, the course term structure and a plan with varied statuses. The roles and the
 * template catalog it wires to are the GOLDEN backbone ({@see RoleFixtures}, {@see TaskTemplateFixtures});
 * this only adds the volatile example layer (DEMO). Not for production.
 */
final class DemoFixtures extends AbstractDemoFixture implements DependentFixtureInterface
{
    public function __construct(private readonly SchoolCalendar $schoolCalendar)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $year = SchoolYear::current(new \DateTimeImmutable());
        $startYear = (int) substr($year, 0, 4);

        // Term structure of the demo course, so deadline anchors and yearly generation have a calendar
        // to work against. Dates are illustrative (a typical Spanish IES trimester layout).
        $academicYear = (new AcademicYear())
            ->setSchoolYear($year)
            ->setTerm1Start(new \DateTimeImmutable($startYear.'-09-15'))
            ->setTerm1End(new \DateTimeImmutable($startYear.'-12-22'))
            ->setTerm2Start(new \DateTimeImmutable(($startYear + 1).'-01-08'))
            ->setTerm2End(new \DateTimeImmutable(($startYear + 1).'-03-27'))
            ->setTerm3Start(new \DateTimeImmutable(($startYear + 1).'-04-07'))
            ->setTerm3End(new \DateTimeImmutable(($startYear + 1).'-06-22'));
        $manager->persist($academicYear);

        // The leadership people also teach in a department: they carry their leadership role AND the
        // Docente role, so they show up as valid responsibles for a teaching task in their department.
        $director = (new User())->setFullName('Ana Directora')->setEmail('director@centro.test')->addAssignedRole($this->role('direction'))->addAssignedRole($this->role('teacher'));
        $ticUser = (new User())->setFullName('Tomás TIC')->setEmail('tic@centro.test')->addAssignedRole($this->role('tic'));
        $headStudies = (new User())->setFullName('Luis Jefatura')->setEmail('jefatura@centro.test')->addAssignedRole($this->role('head_of_studies'))->addAssignedRole($this->role('teacher'));
        $mathsHead = (new User())->setFullName('María Matemáticas')->setEmail('mates@centro.test')->addAssignedRole($this->role('head_dept'))->addAssignedRole($this->role('teacher'));
        $teacher = (new User())->setFullName('Pedro Docente')->setEmail('profe@centro.test')->addAssignedRole($this->role('teacher'));
        // A few more maths teachers so the per-department "Docente" role resolves to several people
        // and the responsibility preview can be seen with more than one holder.
        $mathsTeachers = array_map(
            fn (array $person): User => (new User())->setFullName($person[0])->setEmail($person[1])->addAssignedRole($this->role('teacher')),
            [['Lucía Álgebra', 'lucia@centro.test'], ['Carlos Geometría', 'carlos@centro.test'], ['Nuria Cálculo', 'nuria@centro.test']],
        );
        array_map($manager->persist(...), [$director, $ticUser, $headStudies, $mathsHead, $teacher, ...$mathsTeachers]);

        // Departments only — there are no leadership "boxes": the chain of command comes from the
        // ranked roles people hold. Everyone teaches in Maths in this demo.
        $maths = (new Unit())->setCode('maths')->setName('Departamento de Matemáticas');
        $manager->persist($maths);

        $director->setUnit($maths);
        $headStudies->setUnit($maths);
        $mathsHead->setUnit($maths);
        $teacher->setUnit($maths);
        array_map(static fn (User $t): User => $t->setUnit($maths), $mathsTeachers);

        $reportTpl = $this->getReference(TaskTemplateFixtures::REPORT, TaskTemplate::class);
        $meetingTpl = $this->getReference(TaskTemplateFixtures::MEETING, TaskTemplate::class);

        // Some real Spanish public holidays within the course, so the calendar shows non-teaching
        // days and deadline validation has something to reject. Weekends are non-teaching on their
        // own and are NOT stored here.
        $holidays = [
            [sprintf('%d-11-01', $startYear), 'Todos los Santos'],
            [sprintf('%d-12-06', $startYear), 'Día de la Constitución'],
            [sprintf('%d-12-08', $startYear), 'Inmaculada Concepción'],
            [sprintf('%d-01-06', $startYear + 1), 'Reyes'],
            [sprintf('%d-05-01', $startYear + 1), 'Día del Trabajo'],
        ];
        $blockedKeys = [];
        foreach ($holidays as [$date, $description]) {
            $manager->persist((new NonLectiveDay())->setDate(new \DateTimeImmutable($date))->setDescription($description));
            $blockedKeys[] = $date;
        }

        // A plan for the course with a spread of deadlines, assignees and statuses. Nudged onto a
        // teaching day so no demo task lands on a weekend or holiday.
        // A plan spread over deadlines/statuses. Department tasks live in Maths; the centre ones
        // (head of studies' responsibility) carry no department (unit null).
        $plan = [
            [$reportTpl, sprintf('%d-06-30', $startYear + 1), $maths, $mathsHead, 'in_progress'],
            [$meetingTpl, sprintf('%d-10-15', $startYear), $maths, $mathsHead, 'validated'],
            [$reportTpl, sprintf('%d-01-31', $startYear + 1), null, $headStudies, 'submitted'],
            [$meetingTpl, sprintf('%d-11-20', $startYear), null, $headStudies, 'done'],
        ];
        $headDeptRole = $this->role('head_dept');
        $headStudiesRole = $this->role('head_of_studies');
        foreach ($plan as [$tpl, $due, $unit, $assignee, $status]) {
            $dueDate = $this->toLectiveDay(new \DateTimeImmutable($due), $blockedKeys, false);
            $task = Task::fromTemplate($tpl, $year, $dueDate);
            // Responsibility = role + (department for per-department roles): the Maths tasks are the
            // department head's (head_dept in Matemáticas); the centre ones are the head of studies'
            // (centre-wide, no department). Resolved live → they follow whoever holds that role.
            $responsibility = null !== $unit
                ? new TaskResponsibility($headDeptRole, $maths)
                : new TaskResponsibility($headStudiesRole, null);
            $task->setUnit($unit)->setResponsibility($responsibility)->setAssignedUser($assignee)->setStatus($status);
            if ('validated' === $status) {
                // Closed: freeze who did it (the completion subscriber only fires on a real transition).
                $task->setCompletedBy($assignee);
            }
            $manager->persist($task);
        }

        // Assigned to the teacher across time buckets so the personal agenda demoes well today:
        // one overdue (soft), one due today, one this week, and one already done. Each nudged onto a
        // teaching day — forward for today/upcoming, backward for overdue.
        $today = new \DateTimeImmutable('today');
        $teacherPlan = [
            ['Preparar el acta de la CCP', 'Redactar y subir el acta de la última Comisión de Coordinación Pedagógica.', $today, false],
            ['Entregar la programación de aula', 'Revisar la programación con los criterios del departamento antes de entregarla.', $today->modify('+3 days'), false],
            ['Revisar las propuestas de mejora del trimestre', null, $today->modify('-2 days'), false],
            ['Actualizar el tablón del aula', null, $today->modify('-10 days'), true],
        ];
        $teacherTasks = [];
        foreach ($teacherPlan as [$title, $description, $due, $done]) {
            $dueDate = $this->toLectiveDay($due, $blockedKeys, $due >= $today);
            $task = new Task($title, $year, $dueDate, TaskType::SIMPLE);
            // Responsibility = "profesor de Matemáticas" (per-department role): resolves to the teacher.
            $task->setDescription($description)->setUnit($maths)->setAssignedUser($teacher)
                ->setResponsibility(new TaskResponsibility($this->role('teacher'), $maths))->setCheckboxDone($done)->setCreatedBy($director);
            $manager->persist($task);
            $teacherTasks[] = $task;
        }

        // A deliverable task in progress, so the teacher's task detail shows the full workbench.
        $withDeliverable = Task::fromTemplate($reportTpl, $year, $this->toLectiveDay($today->modify('+5 days'), $blockedKeys, true));
        $withDeliverable->setDescription('Memoria anual del departamento con resultados y propuestas para el curso que viene.')
            ->setUnit($maths)->setAssignedUser($teacher)->setResponsibility(new TaskResponsibility($this->role('teacher'), $maths))->setStatus('in_progress')->setCreatedBy($director);
        $manager->persist($withDeliverable);

        // A couple of demo notices for the teacher so the inbox and its badge are not empty.
        $pinned = $teacherTasks[0];
        $pinnedIsToday = $pinned->getDueDate()->format('Y-m-d') === $today->format('Y-m-d');
        $manager->persist(new Notification($teacher, 'task.reminder', sprintf('Tarea próxima: %s', $teacherTasks[1]->getTitle()), 'Vence pronto.', $teacherTasks[1]));
        $manager->persist((new Notification(
            $teacher,
            'task.reminder',
            sprintf('Tarea %s: %s', $pinnedIsToday ? 'de hoy' : 'próxima', $pinned->getTitle()),
            $pinnedIsToday ? 'Vence hoy.' : 'Vence pronto.',
            $pinned,
        ))->markRead());

        $manager->flush();
    }

    /**
     * @return list<class-string>
     */
    public function getDependencies(): array
    {
        return [RoleFixtures::class, TaskTemplateFixtures::class];
    }

    /**
     * The golden role with the given code, wired via its fixture reference.
     *
     * @param string $code the role code
     *
     * @return Role the referenced role
     */
    private function role(string $code): Role
    {
        return $this->getReference(RoleFixtures::ref($code), Role::class);
    }

    /**
     * Nudges a demo date onto a teaching day so no seeded task lands on a weekend or holiday.
     *
     * @param \DateTimeImmutable $date        the candidate date
     * @param list<string>       $blockedKeys 'Y-m-d' keys of the seeded non-teaching days to avoid
     * @param bool               $forward     true to search forward in time, false to search backward
     *
     * @return \DateTimeImmutable the nearest teaching day in the chosen direction
     */
    private function toLectiveDay(\DateTimeImmutable $date, array $blockedKeys, bool $forward): \DateTimeImmutable
    {
        $step = $forward ? '+1 day' : '-1 day';
        while ($this->schoolCalendar->isWeekend($date) || \in_array($date->format('Y-m-d'), $blockedKeys, true)) {
            $date = $date->modify($step);
        }

        return $date;
    }
}
