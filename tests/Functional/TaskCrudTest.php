<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Task;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\TaskType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Creating/editing tasks: a user can create tasks for themselves (and their subordinates), the
 * creator is recorded, and an unrelated user cannot edit someone else's task.
 */
final class TaskCrudTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email, ?Unit $unit = null): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email);
        if (null !== $unit) {
            $user->setUnit($unit);
        }
        $this->em->persist($user);

        return $user;
    }

    public function testNewTaskFormRenders(): void
    {
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $user = $this->user('jefa@centro.test', $unit);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/tareas/nueva');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testCreateTaskRecordsCreatorAndAssignee(): void
    {
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $creator = $this->user('jefa@centro.test', $unit);
        $this->em->flush();
        $this->client->loginUser($creator);

        $crawler = $this->client->request('GET', '/tareas/nueva');
        $form = $crawler->selectButton('Crear tarea')->form();
        $form['task_form[title]'] = 'Preparar la evaluación';
        $form['task_form[dueDate]'] = '2026-09-15';
        $form['task_form[assignedUser]'] = (string) $creator->getId();
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Preparar la evaluación']);
        self::assertNotNull($task);
        self::assertSame($creator->getId(), $task->getCreatedBy()?->getId());
        self::assertSame($creator->getId(), $task->getAssignedUser()?->getId());
    }

    public function testUnrelatedUserCannotEditTask(): void
    {
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $creator = $this->user('jefa@centro.test', $unit);
        $task = new Task('Memoria', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($unit)->setCreatedBy($creator);
        $this->em->persist($task);
        $stranger = $this->user('otro@centro.test');
        $this->em->flush();

        $this->client->loginUser($stranger);
        $this->client->request('GET', '/tareas/'.$task->getId().'/editar');

        self::assertResponseStatusCodeSame(403);
    }
}
