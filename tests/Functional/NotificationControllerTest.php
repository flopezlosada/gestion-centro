<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Notification;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A notification is marked read only when its recipient OPENS it — which also forwards them to what it
 * is about (its task, or back to the inbox). Someone else may not open it.
 */
final class NotificationControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    public function testOpeningANotificationMarksItReadAndForwardsToItsTask(): void
    {
        $user = $this->user('profe@centro.test');
        $task = (new Task('Entregar memoria', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE))->setAssignedUser($user);
        $this->em->persist($task);
        $notification = new Notification($user, 'task_reminder', 'Tu tarea vence pronto', null, $task);
        $this->em->persist($notification);
        $this->em->flush();
        $id = (int) $notification->getId();

        $this->client->loginUser($user);
        $this->client->request('GET', '/avisos/'.$id);

        self::assertResponseRedirects('/tareas/'.$task->getId());
        $this->em->clear();
        self::assertTrue($this->em->getRepository(Notification::class)->find($id)->isRead(), 'abrirlo lo marca leído');
    }

    public function testOpeningATasklessNotificationMarksItReadAndReturnsToTheInbox(): void
    {
        $user = $this->user('profe@centro.test');
        $notification = new Notification($user, 'info', 'Aviso general', 'Sin tarea asociada');
        $this->em->persist($notification);
        $this->em->flush();
        $id = (int) $notification->getId();

        $this->client->loginUser($user);
        $this->client->request('GET', '/avisos/'.$id);

        self::assertResponseRedirects('/avisos');
        $this->em->clear();
        self::assertTrue($this->em->getRepository(Notification::class)->find($id)->isRead());
    }

    public function testAnotherUserCannotOpenSomeoneElsesNotification(): void
    {
        $owner = $this->user('duena@centro.test');
        $me = $this->user('yo@centro.test');
        $notification = new Notification($owner, 'info', 'Aviso privado');
        $this->em->persist($notification);
        $this->em->flush();

        $this->client->loginUser($me);
        $this->client->request('GET', '/avisos/'.$notification->getId());

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertFalse($this->em->getRepository(Notification::class)->find($notification->getId())->isRead(), 'un ajeno no la marca leída');
    }
}
