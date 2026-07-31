<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Notification;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\NotificationChannel;
use App\Enum\NotificationTopic;
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

    /** Elegir canal por sección se guarda en quien lo elige y se relee marcado en la pantalla. */
    public function testEveryoneChoosesTheirOwnChannelPerSection(): void
    {
        $user = $this->user('ajustes@centro.test');
        $this->em->flush();
        $id = (int) $user->getId();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/avisos/ajustes');
        self::assertResponseIsSuccessful();

        // POST directo con el token de la página: el formulario tiene una opción de valor vacío ("como
        // lo tenga la aplicación") y DomCrawler no la modela bien como radio.
        $this->client->request('POST', '/avisos/ajustes', [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'canal_guardia' => 'push',
            'canal_task' => 'email',
        ]);

        self::assertResponseRedirects('/avisos/ajustes');
        $this->em->clear();
        $saved = $this->em->getRepository(User::class)->find($id);
        self::assertNotNull($saved);
        self::assertSame(NotificationChannel::PUSH, $saved->channelFor(NotificationTopic::GUARDIA));
        self::assertSame(NotificationChannel::EMAIL, $saved->channelFor(NotificationTopic::TASK));
        // Lo que no se toca sigue sin elegir: no se inventa un valor por rellenar el formulario.
        self::assertNull($saved->channelFor(NotificationTopic::MEETING));
    }

    /**
     * Un envío que NO habla de una sección la deja como estaba. Distinto de mandarla vacía, que sí
     * significa "vuelve a lo que tenga la aplicación": ausente es "de esto no estoy diciendo nada".
     *
     * Importa el día que se añada una sección nueva y la pantalla se despliegue después, o ante un POST
     * parcial: borrar en silencio lo que la persona eligió hace un mes sería peor que no guardar nada.
     */
    public function testASubmissionThatOmitsASectionLeavesItUntouched(): void
    {
        $user = $this->user('parcial@centro.test');
        $user->setChannelFor(NotificationTopic::GUARDIA, NotificationChannel::PUSH);
        $this->em->flush();
        $id = (int) $user->getId();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/avisos/ajustes');
        // Solo se manda la sección de tareas: de las guardias no se dice nada.
        $this->client->request('POST', '/avisos/ajustes', [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'canal_task' => 'email',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $saved = $this->em->getRepository(User::class)->find($id);
        self::assertNotNull($saved);
        self::assertSame(NotificationChannel::EMAIL, $saved->channelFor(NotificationTopic::TASK));
        self::assertSame(NotificationChannel::PUSH, $saved->channelFor(NotificationTopic::GUARDIA), 'lo que no venía en el envío no se toca');
    }

    /**
     * La invitación de Inicio se enseña solo mientras no se ha elegido nada: es una pregunta que se hace
     * una vez, no un cartel permanente.
     */
    public function testTheHomePromptDisappearsOnceTheChannelsAreChosen(): void
    {
        $user = $this->user('invitacion@centro.test');
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/');
        self::assertSelectorExists('.notice-setup');

        $user->setChannelFor(NotificationTopic::TASK, NotificationChannel::BOTH);
        $this->em->flush();

        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.notice-setup');
    }
}
