<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\DueDate\FixedDate;
use App\DueDate\PerTerm;
use App\Entity\AcademicYear;
use App\Entity\Department;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskTemplate;
use App\Entity\User;
use App\Enum\TaskType;
use App\Enum\TermBoundary;
use App\Service\TaskGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Generation turns the catalogue into a course's tasks: active templates with a deadline rule become
 * tasks (one per resolved date), templates without a rule are skipped, and a re-run adds nothing new.
 */
final class TaskGeneratorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TaskGenerator $generator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->generator = self::getContainer()->get(TaskGenerator::class);
    }

    private function year(): AcademicYear
    {
        $year = (new AcademicYear())
            ->setSchoolYear('2026-2027')
            ->setTerm1Start(new \DateTimeImmutable('2026-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2026-12-22'))
            ->setTerm2Start(new \DateTimeImmutable('2027-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2027-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2027-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2027-06-22'));
        $this->em->persist($year);

        return $year;
    }

    private function template(string $title, bool $active, ?object $rule): TaskTemplate
    {
        $template = (new TaskTemplate())->setTitle($title)->setType(TaskType::SIMPLE)->setActive($active);
        if ($rule instanceof \App\DueDate\DueDateRule) {
            $template->setDueDateRule($rule);
        }
        $this->em->persist($template);

        return $template;
    }

    public function testGeneratesOneTaskPerResolvedDateAndSkipsRuleless(): void
    {
        $year = $this->year();
        $perTerm = $this->template('Acta de reunión', true, new PerTerm(TermBoundary::END));
        $this->template('Tarea sin regla', true, null);
        $this->em->flush();

        $result = $this->generator->generate($year, null);

        self::assertSame(3, $result->created, 'three term-end dates');
        self::assertSame(1, $result->skippedWithoutRule);
        self::assertSame(0, $result->skippedExisting);

        $tasks = $this->em->getRepository(Task::class)->findBy(['schoolYear' => '2026-2027']);
        self::assertCount(3, $tasks);
        foreach ($tasks as $task) {
            self::assertSame($perTerm, $task->getTemplate(), 'linked back to its template');
            self::assertSame('2026-2027', $task->getSchoolYear());
        }
    }

    public function testReRunIsIdempotent(): void
    {
        $year = $this->year();
        $this->template('Memoria', true, new FixedDate(6, 30));
        $this->em->flush();

        $first = $this->generator->generate($year, null);
        self::assertSame(1, $first->created);

        $second = $this->generator->generate($year, null);
        self::assertSame(0, $second->created, 'nothing new on a re-run');
        self::assertSame(1, $second->skippedExisting);
        self::assertCount(1, $this->em->getRepository(Task::class)->findBy(['schoolYear' => '2026-2027']));
    }

    public function testInactiveTemplatesAreNotGenerated(): void
    {
        $year = $this->year();
        $this->template('Retirada', false, new PerTerm(TermBoundary::END));
        $this->em->flush();

        $result = $this->generator->generate($year, null);

        self::assertSame(0, $result->created);
        self::assertCount(0, $this->em->getRepository(Task::class)->findBy(['schoolYear' => '2026-2027']));
    }

    /**
     * A generated task carries the template's role as a real responsibility, and so is a first-class
     * task: it has a responsible, the detail screen can name a role, and the chain of command can find
     * somebody above it.
     *
     * This is the regression that mattered. The role used to land in a separate `assigned_role_id`
     * column that only three places knew how to read — {@see \App\Service\OrganizationHierarchy} was
     * not one of them — so a task straight from the catalogue had NOBODY above it: no validator on the
     * detail, no escalation when it went overdue, and no sign on screen that anything was missing.
     */
    public function testAGeneratedTaskGetsTheTemplateRoleAsItsResponsibility(): void
    {
        $year = $this->year();
        $role = (new Role())->setCode('head_of_studies')->setName('Jefatura de estudios')->setHierarchyLevel(30);
        $this->em->persist($role);
        $holder = (new User())->setFullName('Luis Jefatura')->setEmail('jefatura@centro.test')->addAssignedRole($role);
        $this->em->persist($holder);
        $this->template('Memoria', true, new FixedDate(6, 30))->setResponsibleRole($role);
        $this->em->flush();

        $this->generator->generate($year, null);

        $tasks = $this->em->getRepository(Task::class)->findBy(['schoolYear' => '2026-2027']);
        self::assertCount(1, $tasks);
        $task = $tasks[0];

        self::assertNotNull($task->getResponsibility(), 'the template role becomes a real responsibility');
        self::assertSame($role, $task->responsibleRole());
        // Sin `?->`: el assertNotNull de arriba ya estrechó el tipo y PHPStan rechaza el nullsafe.
        self::assertNull($task->getResponsibility()->getUnit(), 'a template names a function, not a department');
        // Nobody was picked by hand, so the holder of the role is on the hook — resolved live.
        self::assertSame($holder, $task->resolveResponsible());
        self::assertTrue($task->isOwnedBy($holder));
    }

    /**
     * A per-department template is not one task: it is one per department, each with its own holder.
     *
     * "Memoria del departamento" is twenty-one different memorias — delivered, commented and validated
     * separately. Generated as a single task it would have been held by every jefe de departamento at
     * once, and whoever delivered first would have spoken for all of them.
     */
    public function testAPerDepartmentTemplateGeneratesOneTaskPerDepartment(): void
    {
        $year = $this->year();
        $role = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true);
        $this->em->persist($role);
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $lengua = (new Department())->setCode('lengua')->setName('Lengua');
        $this->em->persist($maths);
        $this->em->persist($lengua);
        $mathsHead = (new User())->setFullName('María Mates')->setEmail('mates@centro.test')->setUnit($maths)->addAssignedRole($role);
        $lenguaHead = (new User())->setFullName('Lola Lengua')->setEmail('lengua@centro.test')->setUnit($lengua)->addAssignedRole($role);
        $this->em->persist($mathsHead);
        $this->em->persist($lenguaHead);
        $this->template('Memoria del departamento', true, new FixedDate(6, 30))->setResponsibleRole($role);
        $this->em->flush();

        $result = $this->generator->generate($year, null);

        self::assertSame(2, $result->created, 'una memoria por departamento, no una compartida');

        $tasks = $this->em->getRepository(Task::class)->findBy(['schoolYear' => '2026-2027']);
        $byDepartment = [];
        foreach ($tasks as $task) {
            $byDepartment[(string) $task->getResponsibility()?->getUnit()?->getCode()] = $task;
        }
        // Ordenado antes de comparar: lo que se afirma es QUÉ departamentos tienen tarea, no en qué
        // orden los devuelve el repositorio (los ordena por nombre, así que "Lengua" va antes que
        // "Matemáticas" y fijar el orden aquí era atar el test a un detalle que no se está probando).
        $codes = array_keys($byDepartment);
        sort($codes);
        self::assertSame(['lengua', 'maths'], $codes);
        // Cada una resuelve a SU jefatura, en vivo y sin asignado congelado.
        self::assertSame($mathsHead, $byDepartment['maths']->resolveResponsible());
        self::assertSame($lenguaHead, $byDepartment['lengua']->resolveResponsible());
        self::assertTrue($byDepartment['maths']->isOwnedBy($mathsHead));
        self::assertFalse($byDepartment['maths']->isOwnedBy($lenguaHead), 'la memoria de Mates no es de la jefa de Lengua');
    }

    /**
     * Y la re-ejecución sigue siendo idempotente con la expansión: la clave lleva el departamento, y
     * sin él la segunda pasada encontraría la tarea del primer departamento y daría por generadas las
     * de los otros veinte.
     */
    public function testTheExpansionStaysIdempotentPerDepartment(): void
    {
        $year = $this->year();
        $role = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true);
        $this->em->persist($role);
        foreach (['maths' => 'Matemáticas', 'lengua' => 'Lengua', 'arts' => 'Plástica'] as $code => $name) {
            $this->em->persist((new Department())->setCode($code)->setName($name));
        }
        $this->template('Memoria del departamento', true, new FixedDate(6, 30))->setResponsibleRole($role);
        $this->em->flush();

        self::assertSame(3, $this->generator->generate($year, null)->created);

        $second = $this->generator->generate($year, null);
        self::assertSame(0, $second->created, 'nada nuevo en la segunda pasada');
        self::assertSame(3, $second->skippedExisting, 'las TRES se reconocen, no solo la primera');
        self::assertCount(3, $this->em->getRepository(Task::class)->findBy(['schoolYear' => '2026-2027']));
    }

    /**
     * Un departamento sin jefatura genera igual su tarea, sin responsable. Decisión de Paco
     * (2026-08-03): saltárselo haría desaparecer del plan del curso justo al departamento cuyo hueco
     * hay que ver. Sale como "Sin asignar", y en cuanto se nombre titular la tarea le sigue sola.
     */
    public function testADepartmentWithNoHolderStillGetsItsTaskUnassigned(): void
    {
        $year = $this->year();
        $role = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true);
        $this->em->persist($role);
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $orphan = (new Department())->setCode('arts')->setName('Plástica');
        $this->em->persist($maths);
        $this->em->persist($orphan);
        $this->em->persist((new User())->setFullName('María Mates')->setEmail('mates@centro.test')->setUnit($maths)->addAssignedRole($role));
        $this->template('Memoria del departamento', true, new FixedDate(6, 30))->setResponsibleRole($role);
        $this->em->flush();

        self::assertSame(2, $this->generator->generate($year, null)->created, 'el departamento sin jefatura también genera');

        $tasks = $this->em->getRepository(Task::class)->findBy(['schoolYear' => '2026-2027']);
        $orphanTask = array_values(array_filter($tasks, static fn (Task $t): bool => $orphan === $t->getResponsibility()?->getUnit()));
        self::assertCount(1, $orphanTask);
        self::assertNull($orphanTask[0]->resolveResponsible(), 'sin titular, la tarea se lee como "Sin asignar"');
    }

    /**
     * A template with no responsible role still generates: the task simply has nobody yet, and says so
     * by resolving to null instead of pretending.
     */
    public function testATemplateWithoutARoleGeneratesATaskWithNoResponsibility(): void
    {
        $year = $this->year();
        $this->template('Memoria sin dueño', true, new FixedDate(6, 30));
        $this->em->flush();

        $this->generator->generate($year, null);

        $tasks = $this->em->getRepository(Task::class)->findBy(['schoolYear' => '2026-2027']);
        self::assertCount(1, $tasks);
        self::assertNull($tasks[0]->getResponsibility());
        self::assertNull($tasks[0]->resolveResponsible());
    }
}
