<?php

declare(strict_types=1);

namespace App\DataFixtures;

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
            [$reportTpl, '2026-06-30', $maths, $mathsHead, 'in_progress'],
            [$meetingTpl, '2025-10-15', $maths, $mathsHead, 'validated'],
            [$reportTpl, '2026-01-31', $studies, $headStudies, 'submitted'],
            [$meetingTpl, '2025-11-20', $studies, $headStudies, 'done'],
        ];
        foreach ($plan as [$tpl, $due, $unit, $assignee, $status]) {
            $task = Task::fromTemplate($tpl, $year, new \DateTimeImmutable($due));
            $task->setUnit($unit)->setAssignedUser($assignee)->setStatus($status);
            $manager->persist($task);
        }

        // A couple assigned to the teacher, left pending, so their agenda is not empty.
        foreach (['2025-12-01', '2026-03-15'] as $due) {
            $task = Task::fromTemplate($meetingTpl, $year, new \DateTimeImmutable($due));
            $task->setUnit($maths)->setAssignedUser($teacher);
            $manager->persist($task);
        }

        $manager->flush();
    }
}
