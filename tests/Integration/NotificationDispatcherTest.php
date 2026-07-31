<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\NotificationChannel;
use App\Enum\NotificationTopic;
use App\Service\AppSettings;
use App\Service\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Delivery follows the door: nobody gets an e-mail (or a push) telling them to open an application
 * they cannot sign into. The in-app notice is written either way, so it is waiting for them the day
 * their access opens instead of being lost — the reminder engine fires each reminder on one exact
 * day and never again.
 *
 * And, past that door, delivery follows what each person chose per section
 * ({@see \App\Enum\NotificationTopic}): the phone, the e-mail or both.
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
        // El interruptor de acceso vive en la base de datos y NO se deshace entre tests: sin esto, los
        // que cierran el acceso dejaban a los siguientes sin recibir nada, y el fallo aparecía en el test
        // equivocado según el orden de ejecución. Cada uno arranca con la puerta abierta y la cierra si
        // es lo que va a probar.
        $this->settings->setLoginOpen(true);
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
     * The centre's actual complaint: "activo los avisos del móvil y me siguen llegando al correo".
     * Choosing the phone for a section has to STOP the e-mails of that section, or it is not a setting.
     */
    public function testChoosingThePhoneStopsTheEmailsOfThatSection(): void
    {
        $user = $this->user('movil@centro.test');
        $user->setChannelFor(NotificationTopic::GUARDIA, NotificationChannel::PUSH);
        $this->em->flush();

        $this->dispatcher->dispatch($user, 'guardia.assigned', 'Nueva guardia');

        self::assertEmailCount(0);
    }

    /**
     * And the other way round: asking for e-mail wins even over the app's own "this one is too
     * last-minute for an inbox" rule (an agenda nudge fires minutes before the event). It is an
     * explicit instruction from the person, so the app does not get to overrule it.
     */
    public function testChoosingEmailWinsOverThePushOnlyDefault(): void
    {
        $user = $this->user('correo@centro.test');
        $user->setChannelFor(NotificationTopic::AGENDA, NotificationChannel::EMAIL);
        $this->em->flush();

        $this->dispatcher->dispatch($user, 'event.reminder', 'Empieza en 10 minutos');

        self::assertEmailCount(1, null, 'sin elegir, un aviso de agenda no lleva correo: elegirlo lo cambia');
    }

    /** Each section is set on its own: silencing the guardias must not silence the tasks. */
    public function testEachSectionIsIndependent(): void
    {
        $user = $this->user('mixto@centro.test');
        $user->setChannelFor(NotificationTopic::GUARDIA, NotificationChannel::PUSH);
        $user->setChannelFor(NotificationTopic::TASK, NotificationChannel::EMAIL);
        $this->em->flush();

        $this->dispatcher->dispatch($user, 'guardia.assigned', 'Nueva guardia');
        $this->dispatcher->dispatch($user, 'task.reminder', 'Tarea próxima');

        self::assertEmailCount(1, null, 'solo la tarea manda correo');
    }

    /** An unclassified kind keeps the app's default instead of falling into somebody's "solo móvil". */
    public function testAKindOutsideEverySectionKeepsTheDefault(): void
    {
        $user = $this->user('otros@centro.test');
        $user->setChannelFor(NotificationTopic::TASK, NotificationChannel::PUSH);
        $this->em->flush();

        $this->dispatcher->dispatch($user, 'sistema.aviso', 'Algo que no es de ninguna sección');

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
