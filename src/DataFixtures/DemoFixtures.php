<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\AcademicYear;
use App\Entity\NonLectiveDay;
use App\Entity\Notification;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskTemplate;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\TaskType;
use App\Service\SchoolCalendar;
use App\Util\SchoolYear;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Demo data to see the app working locally: a small org chart (management → head of studies →
 * maths), a few people, task templates and a plan for the current course with varied statuses.
 *
 * Not for production. Load with: ddev exec php bin/console doctrine:fixtures:load
 */
final class DemoFixtures extends Fixture
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

        // Direction manages via the permission matrix (write on Administration) WITHOUT the superuser
        // flag: it reaches /admin but is not ROLE_ADMIN. TIC is the actual superuser (admin flag).
        // Task access is universal and scoped by the org chart, so the other roles carry no matrix
        // permissions — they are pure responsibility markers used for assignment and hierarchy.
        $direction = (new Role())->setCode('direction')->setName('Dirección')->setLevel(Area::ADMINISTRATION, PermissionLevel::WRITE);
        $tic = (new Role())->setCode('tic')->setName('TIC')->setAdmin(true);
        $headDept = (new Role())->setCode('head_dept')->setName('Jefatura de departamento');
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente');
        array_map($manager->persist(...), [$direction, $tic, $headDept, $teacherRole]);

        $director = (new User())->setFullName('Ana Directora')->setEmail('director@centro.test')->addAssignedRole($direction);
        $ticUser = (new User())->setFullName('Tomás TIC')->setEmail('tic@centro.test')->addAssignedRole($tic);
        $headStudies = (new User())->setFullName('Luis Jefatura')->setEmail('jefatura@centro.test')->addAssignedRole($headDept);
        $mathsHead = (new User())->setFullName('María Matemáticas')->setEmail('mates@centro.test')->addAssignedRole($headDept);
        $teacher = (new User())->setFullName('Pedro Docente')->setEmail('profe@centro.test')->addAssignedRole($teacherRole);
        array_map($manager->persist(...), [$director, $ticUser, $headStudies, $mathsHead, $teacher]);

        $management = (new Unit())->setCode('management')->setName('Dirección')->setManager($director);
        $studies = (new Unit())->setCode('head_of_studies')->setName('Jefatura de estudios')->setManager($headStudies)->setParent($management);
        $maths = (new Unit())->setCode('maths')->setName('Departamento de Matemáticas')->setManager($mathsHead)->setParent($studies);
        array_map($manager->persist(...), [$management, $studies, $maths]);

        $director->setUnit($management);
        $headStudies->setUnit($studies);
        $mathsHead->setUnit($maths);
        $teacher->setUnit($maths);

        $reportTpl = (new TaskTemplate())->setTitle('Memoria del departamento')->setType(TaskType::WITH_DELIVERABLE)->setResponsibleRole($headDept)->setRequiresDocument(true);
        $meetingTpl = (new TaskTemplate())->setTitle('Acta de reunión de departamento')->setType(TaskType::SIMPLE)->setResponsibleRole($headDept);
        $manager->persist($reportTpl);
        $manager->persist($meetingTpl);

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
        $plan = [
            [$reportTpl, sprintf('%d-06-30', $startYear + 1), $maths, $mathsHead, 'in_progress'],
            [$meetingTpl, sprintf('%d-10-15', $startYear), $maths, $mathsHead, 'validated'],
            [$reportTpl, sprintf('%d-01-31', $startYear + 1), $studies, $headStudies, 'submitted'],
            [$meetingTpl, sprintf('%d-11-20', $startYear), $studies, $headStudies, 'done'],
        ];
        foreach ($plan as [$tpl, $due, $unit, $assignee, $status]) {
            $dueDate = $this->toLectiveDay(new \DateTimeImmutable($due), $blockedKeys, false);
            $task = Task::fromTemplate($tpl, $year, $dueDate);
            $task->setUnit($unit)->setAssignedUser($assignee)->setStatus($status);
            $manager->persist($task);
        }

        // Assigned to the teacher across time buckets so the personal agenda demoes well today:
        // one overdue (soft), one due today, one this week, and one already done. Each nudged onto a
        // teaching day — forward for today/upcoming, backward for overdue — so none lands on a
        // weekend or holiday (fixing e.g. "acta de la CCP" landing on a Saturday).
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
            $task->setDescription($description)->setUnit($maths)->setAssignedUser($teacher)->setCheckboxDone($done)->setCreatedBy($director);
            $manager->persist($task);
            $teacherTasks[] = $task;
        }

        // A deliverable task in progress, so the teacher's task detail shows the full workbench
        // (action "Entregar" + the deliverable reference form).
        $withDeliverable = Task::fromTemplate($reportTpl, $year, $this->toLectiveDay($today->modify('+5 days'), $blockedKeys, true));
        $withDeliverable->setDescription('Memoria anual del departamento con resultados y propuestas para el curso que viene.')
            ->setUnit($maths)->setAssignedUser($teacher)->setStatus('in_progress')->setCreatedBy($director);
        $manager->persist($withDeliverable);

        // A couple of demo notices for the teacher so the inbox and its badge are not empty. The
        // wording follows the ACTUAL due date after nudging: the first task is only "de hoy" when it
        // really lands on today (it does not when today is a weekend/holiday and it was pushed on).
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
     * Nudges a demo date onto a teaching day so no seeded task lands on a weekend or holiday. Reuses
     * {@see SchoolCalendar::isWeekend()} for the weekend rule, but checks the holidays against the
     * given keys rather than the database, since the seeded rows are not flushed yet at load time.
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

