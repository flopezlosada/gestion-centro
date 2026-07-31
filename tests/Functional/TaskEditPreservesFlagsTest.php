<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Department;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\User;
use App\Enum\DeliverableRequirement;
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
        // A centre-wide role, held by the editor so they are a valid responsible person for it.
        $role = (new Role())->setCode('direction')->setName('Dirección');
        $this->em->persist($role);
        $editor = (new User())->setFullName('Directora Test')->setEmail('direccion@centro.test')->setUnit($dept);
        $editor->addAssignedRole($role);
        $this->em->persist($editor);

        // A FIXED weekday deadline, not "today": the form refuses a deadline that falls on a weekend, so
        // a relative date would make this test fail every Saturday and Sunday for the wrong reason.
        $dueDate = new \DateTimeImmutable('2026-06-30');
        $task = new Task('Memoria del departamento', SchoolYear::current($dueDate), $dueDate, TaskType::WITH_DELIVERABLE);
        // The template's choice: it DOES declare a checkbox, and it also wants a document. The derived
        // getter therefore reads false, which is exactly the value that used to leak into the column.
        // The responsibility carries NO department, because a centre-wide role has none — pairing one
        // with a department leaves the form unable to offer a valid responsible person.
        $task->setRequiresCheckbox(true)->setDeliverable(DeliverableRequirement::LINK)
            ->setResponsibility(new TaskResponsibility($role, null))
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
        // A 422 here would mean the form rejected its OWN prefilled values, which is a different bug than
        // the one under test — say so, instead of failing with a bare status mismatch.
        self::assertResponseRedirects(null, null, 'el formulario debe aceptar sus propios valores precargados');

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
