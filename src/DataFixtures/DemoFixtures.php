<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Notification;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskTemplate;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\TaskType;
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
    public function load(ObjectManager $manager): void
    {
        $year = SchoolYear::current(new \DateTimeImmutable());
        $startYear = (int) substr($year, 0, 4);

        $direction = (new Role())->setCode('direction')->setName('Dirección')->setAdmin(true);
        $headDept = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setLevel(Area::TASK, PermissionLevel::WRITE);
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente')->setLevel(Area::TASK, PermissionLevel::READ);
        array_map($manager->persist(...), [$direction, $headDept, $teacherRole]);

        $director = (new User())->setFullName('Ana Directora')->setEmail('director@centro.test')->addAssignedRole($direction);
        $headStudies = (new User())->setFullName('Luis Jefatura')->setEmail('jefatura@centro.test')->addAssignedRole($headDept);
        $mathsHead = (new User())->setFullName('María Matemáticas')->setEmail('mates@centro.test')->addAssignedRole($headDept);
        $teacher = (new User())->setFullName('Pedro Docente')->setEmail('profe@centro.test')->addAssignedRole($teacherRole);
        array_map($manager->persist(...), [$director, $headStudies, $mathsHead, $teacher]);

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

        // A plan for the course with a spread of deadlines, assignees and statuses.
        $plan = [
            [$reportTpl, sprintf('%d-06-30', $startYear + 1), $maths, $mathsHead, 'in_progress'],
            [$meetingTpl, sprintf('%d-10-15', $startYear), $maths, $mathsHead, 'validated'],
            [$reportTpl, sprintf('%d-01-31', $startYear + 1), $studies, $headStudies, 'submitted'],
            [$meetingTpl, sprintf('%d-11-20', $startYear), $studies, $headStudies, 'done'],
        ];
        foreach ($plan as [$tpl, $due, $unit, $assignee, $status]) {
            $task = Task::fromTemplate($tpl, $year, new \DateTimeImmutable($due));
            $task->setUnit($unit)->setAssignedUser($assignee)->setStatus($status);
            $manager->persist($task);
        }

        // Assigned to the teacher across time buckets so the personal agenda demoes well today:
        // one overdue (soft), one due today, one this week, and one already done.
        $today = new \DateTimeImmutable('today');
        $teacherPlan = [
            ['Preparar el acta de la CCP', $today, false],
            ['Entregar la programación de aula', $today->modify('+3 days'), false],
            ['Revisar las propuestas de mejora del trimestre', $today->modify('-2 days'), false],
            ['Actualizar el tablón del aula', $today->modify('-10 days'), true],
        ];
        $teacherTasks = [];
        foreach ($teacherPlan as [$title, $due, $done]) {
            $task = new Task($title, $year, $due, TaskType::SIMPLE);
            $task->setUnit($maths)->setAssignedUser($teacher)->setCheckboxDone($done);
            $manager->persist($task);
            $teacherTasks[] = $task;
        }

        // A couple of demo notices for the teacher so the inbox and its badge are not empty.
        $manager->persist(new Notification($teacher, 'task.reminder', sprintf('Tarea próxima: %s', $teacherTasks[1]->getTitle()), 'Vence en 3 días.', $teacherTasks[1]));
        $manager->persist((new Notification($teacher, 'task.reminder', sprintf('Tarea de hoy: %s', $teacherTasks[0]->getTitle()), 'Vence hoy.', $teacherTasks[0]))->markRead());

        $manager->flush();
    }
}
