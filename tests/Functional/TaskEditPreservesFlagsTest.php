<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Department;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\User;
use App\Enum\TaskType;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Editing a task must not silently rewrite the flags the form does not offer.
 *
 * The bug this pins: `requires_checkbox` is only ever chosen on the task TEMPLATE, yet the task form's
 * data object carried it, filled from the DERIVED getter ({@see Task::requiresCheckbox()}, which is
 * `checkbox && !document`) and written straight back. So opening a task with a deliverable and pressing
 * Guardar — changing nothing — persisted requires_checkbox = 0. Untick "requiere documento" later and
 * you were left with a task that could not be closed in ANY way: no quick tick, no deliverable.
 */
final class TaskEditPreservesFlagsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testEditingATaskWithADeliverableKeepsItsCheckboxColumn(): void
    {
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        // Direction, so the editor is allowed to open the form for someone else's task.
        $role = (new Role())->setCode('direction')->setName('Dirección')->setAdmin(true);
        $this->em->persist($role);
        $editor = (new User())->setFullName('Directora Test')->setEmail('direccion@centro.test')->setUnit($dept);
        $editor->addAssignedRole($role);
        $this->em->persist($editor);

        $today = new \DateTimeImmutable('today');
        $task = new Task('Memoria del departamento', SchoolYear::current($today), $today, TaskType::WITH_DELIVERABLE);
        // The template's choice: it DOES declare a checkbox, and it also wants a document. The derived
        // getter therefore reads false, which is exactly the value that used to leak into the column.
        $task->setRequiresCheckbox(true)->setRequiresDocument(true)
            ->setResponsibility(new TaskResponsibility($role, $dept))
            ->setUnit($dept)
            ->setAssignedUser($editor)
            ->setCreatedBy($editor);
        $this->em->persist($task);
        $this->em->flush();
        $taskId = (int) $task->getId();

        $this->client->loginUser($editor);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId.'/editar');
        self::assertResponseIsSuccessful();
        // Save without changing a thing: the plain "open and Guardar" that used to corrupt the row.
        $this->client->submit($crawler->selectButton('Guardar')->form());
        self::assertResponseRedirects();

        $this->em->clear();
        $reloaded = $this->em->getRepository(Task::class)->find($taskId);
        self::assertNotNull($reloaded);
        // Read the COLUMN, not the derived getter: the getter would answer false either way while the
        // document flag is on, which is what hid this for so long.
        self::assertTrue(
            (bool) self::getContainer()->get(EntityManagerInterface::class)
                ->getConnection()
                ->fetchOne('SELECT requires_checkbox FROM task WHERE id = ?', [$taskId]),
            'guardar sin tocar nada no puede apagar la casilla que puso la plantilla',
        );
    }
}
