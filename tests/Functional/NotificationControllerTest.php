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

    /**
     * «Borrar leídos» se lleva solo lo que la persona ya abrió: lo que no ha visto se queda, y los
     * avisos de los demás, leídos o no, ni se tocan.
     */
    public function testClearingReadNotificationsKeepsTheUnreadAndEveryoneElses(): void
    {
        $me = $this->user('borra@centro.test');
        $other = $this->user('otra@centro.test');
        $myRead = (new Notification($me, 'info', 'Leído mío'))->markRead();
        $myUnread = new Notification($me, 'info', 'Sin abrir mío');
        $othersRead = (new Notification($other, 'info', 'Leído ajeno'))->markRead();
        foreach ([$myRead, $myUnread, $othersRead] as $n) {
            $this->em->persist($n);
        }
        $this->em->flush();
        $ids = array_map(static fn (Notification $n): int => (int) $n->getId(), [$myRead, $myUnread, $othersRead]);

        $this->client->loginUser($me);
        $crawler = $this->client->request('GET', '/avisos');
        $this->client->submit($crawler->selectButton('Borrar leídos')->form());

        self::assertResponseRedirects('/avisos');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Borrado 1 aviso leído.');
        $this->em->clear();
        $repo = $this->em->getRepository(Notification::class);
        self::assertNull($repo->find($ids[0]), 'el leído propio se borra');
        self::assertNotNull($repo->find($ids[1]), 'el no leído propio se queda');
        self::assertNotNull($repo->find($ids[2]), 'el leído ajeno no se toca');
    }

    /** Sin token válido no se borra nada. */
    public function testClearingReadNotificationsRequiresAValidToken(): void
    {
        $me = $this->user('token@centro.test');
        $read = (new Notification($me, 'info', 'Leído'))->markRead();
        $this->em->persist($read);
        $this->em->flush();

        $this->client->loginUser($me);
        $this->client->request('POST', '/avisos/borrar-leidos', ['_token' => 'falso']);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Notification::class)->find($read->getId()));
    }

    /** El botón solo sale cuando hay algo leído que borrar: con todo sin abrir, sobra. */
    public function testTheClearButtonOnlyShowsWhenThereIsSomethingRead(): void
    {
        $me = $this->user('boton@centro.test');
        $unread = new Notification($me, 'info', 'Sin abrir');
        $this->em->persist($unread);
        $this->em->flush();

        $this->client->loginUser($me);
        $this->client->request('GET', '/avisos');
        self::assertSelectorNotExists('.avisos-clear');

        $unread->markRead();
        $this->em->flush();

        $this->client->request('GET', '/avisos');
        self::assertSelectorExists('.avisos-clear');
    }
}
