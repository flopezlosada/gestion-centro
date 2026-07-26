<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Notification;
use App\Entity\User;
use App\Service\AppSettings;
use App\Service\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Delivery follows the door: nobody gets an e-mail (or a push) telling them to open an application
 * they cannot sign into. The in-app notice is written either way, so it is waiting for them the day
 * their access opens instead of being lost — the reminder engine fires each reminder on one exact
 * day and never again.
 */
final class NotificationDispatcherTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AppSettings $settings;
    private NotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->settings = self::getContainer()->get(AppSettings::class);
        $this->dispatcher = self::getContainer()->get(NotificationDispatcher::class);
    }

    public function testAnActiveUserIsEmailedWhileSignInIsOpen(): void
    {
        $this->dispatcher->dispatch($this->user('dentro@centro.test'), 'task.reminder', 'Tarea próxima');

        self::assertEmailCount(1);
    }

    public function testADeactivatedUserIsNotEmailedButStillGetsTheNotice(): void
    {
        $user = $this->user('baja@centro.test');
        $user->setActive(false);
        $this->em->flush();

        $notification = $this->dispatcher->dispatch($user, 'task.reminder', 'Tarea próxima');

        self::assertEmailCount(0);
        self::assertNotNull($this->em->getRepository(Notification::class)->find($notification->getId()));
    }

    public function testWhileClosedSomeoneWithoutEarlyAccessIsNotEmailed(): void
    {
        $this->settings->setLoginOpen(false);

        $this->dispatcher->dispatch($this->user('espera@centro.test'), 'task.reminder', 'Tarea próxima');

        self::assertEmailCount(0);
    }

    public function testWhileClosedSomeoneWithEarlyAccessIsEmailed(): void
    {
        $this->settings->setLoginOpen(false);
        $user = $this->user('beta@centro.test');
        $user->setEarlyAccess(true);
        $this->em->flush();

        $this->dispatcher->dispatch($user, 'task.reminder', 'Tarea próxima');

        self::assertEmailCount(1);
    }

    /**
     * A plain active user, persisted.
     *
     * @param string $email the address, unique per test
     *
     * @return User the persisted user
     */
    private function user(string $email): User
    {
        $user = (new User())->setFullName('Docente Test')->setEmail($email);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
