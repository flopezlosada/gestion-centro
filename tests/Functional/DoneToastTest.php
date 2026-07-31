<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Department;
use App\Entity\PersonalEvent;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskType;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Marking something done on Inicio makes its row VANISH from the checklist (it moves to the done
 * bucket, which Inicio does not paint). Without a trace of what happened there is no way to tell a
 * deliberate tick from a mis-tap, and no way back that does not involve hunting the task down on
 * another screen — so the tick leaves a toast naming what was ticked, with an "Deshacer" that posts to
 * the very same toggle.
 *
 * The toast is server-rendered on purpose (the undo is an ordinary form), which is exactly why it can
 * be pinned by these tests instead of only by a browser.
 */
final class DoneToastTest extends WebTestCase
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

    /**
     * A task due today (so it lands in Inicio's "Por hacer"), owned by the user who will tick it.
     *
     * @return array{0: User, 1: int} the owner and the task id
     */
    private function seedTaskDueToday(): array
    {
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $owner = $this->user('profe@centro.test');
        $owner->setUnit($dept);
        // PHP's default zone, which is the one the home resolves "today" in. A fixed Europe/Madrid makes
        // this test fail between 22:00 and midnight UTC (CI's zone), when Madrid is already on the next day.
        $today = new \DateTimeImmutable('today');
        $task = new Task('Memoria del departamento', SchoolYear::current($today), $today, TaskType::SIMPLE);
        $task->setAssignedUser($owner)->setCreatedBy($owner);
        $this->em->persist($task);
        $this->em->flush();

        return [$owner, (int) $task->getId()];
    }

    public function testTickingATaskShowsAToastNamingItWithAnUndo(): void
    {
        [$owner, $taskId] = $this->seedTaskDueToday();
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->filter('form.tasklist__check-form')->first()->form());
        $crawler = $this->client->followRedirect();

        self::assertSelectorExists('[data-toast]');
        // Names WHAT was ticked: a bare "Hecho" would not tell you which row disappeared.
        self::assertSelectorTextContains('.toast__title', 'Memoria del departamento');
        self::assertSelectorTextContains('.toast__label', 'Hecho');
        // The undo posts to the same toggle — marking is reversible, so no second route is needed.
        self::assertSame(
            '/tareas/'.$taskId.'/hecho',
            $crawler->filter('form.toast__undo')->attr('action'),
        );
        // And the row really is gone from the checklist, which is why the toast has to exist. Asserted on
        // the row selector, not on the title text: the title is also IN the toast, so a text search would
        // find it there and pass for the wrong reason.
        self::assertSelectorNotExists('.tasklist__item');
    }

    public function testThePresentedUndoActuallyPutsTheTaskBack(): void
    {
        // The button is worthless if it only looks right: submit the rendered form and check the state.
        [$owner, $taskId] = $this->seedTaskDueToday();
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->filter('form.tasklist__check-form')->first()->form());
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('form.toast__undo')->form());

        self::assertResponseRedirects('/');
        $this->em->clear();
        self::assertFalse($this->em->getRepository(Task::class)->find($taskId)?->isCheckboxDone(), 'deshacer devuelve la tarea a pendiente');
    }

    public function testUndoingDoesNotOfferAnotherUndo(): void
    {
        // Once recovered the row is visible again, so the escape hatch has done its job; chaining
        // undo-the-undo would just be a loop to get lost in.
        [$owner] = $this->seedTaskDueToday();
        $this->client->loginUser($owner);

        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->filter('form.tasklist__check-form')->first()->form());
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('form.toast__undo')->form());
        $this->client->followRedirect();

        self::assertSelectorExists('[data-toast]', 'sí confirma que se ha recuperado');
        self::assertSelectorTextContains('.toast__label', 'Otra vez pendiente');
        self::assertSelectorNotExists('form.toast__undo');
    }

    public function testTickingAReminderAlsoShowsTheToast(): void
    {
        // Same component for the other kind of row: a personal reminder without a time.
        $owner = $this->user('profe@centro.test');
        // Sin zona explícita, como el resto de los tests de Inicio: así el recordatorio cae en el MISMO
        // día que la pantalla resuelve como hoy, y "Por hacer" lo pinta como fila con su casilla.
        // Ojo: esto ya no tiene red. "Por hacer" solo pinta lo que queda por delante; lo que cae en
        // vencidas se resume en una línea SIN casilla, así que un desfase de zona no dejaría el test en
        // otro tramo del mismo bloque — lo dejaría sin formulario que enviar.
        $today = new \DateTimeImmutable('today');
        $event = (new PersonalEvent($owner, 'Llamar a la editorial', $today))->setAllDay(true);
        $this->em->persist($event);
        $this->em->flush();
        $eventId = (int) $event->getId();

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->filter('form.tasklist__check-form')->first()->form());
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.toast__title', 'Llamar a la editorial');
        self::assertSame(
            '/agenda/'.$eventId.'/hecho',
            $crawler->filter('form.toast__undo')->attr('action'),
        );
    }

    public function testUndoingFromTheCalendarStaysOnThatDay(): void
    {
        // The toast travels with the day the tick came from, so deshacer does not teleport you to Inicio
        // either — otherwise the escape hatch would itself lose the page you were on.
        $owner = $this->user('profe@centro.test');
        // A timed appointment at midday: what the app actually stores when you pick an hour, and far
        // enough from midnight that no runner-vs-Madrid offset can move it to a neighbouring day.
        $day = (new \DateTimeImmutable('+3 days'))->setTime(12, 0);
        $event = new PersonalEvent($owner, 'Reunión de nivel', $day);
        $this->em->persist($event);
        $this->em->flush();
        $dayStr = $day->format('Y-m-d');

        $this->client->loginUser($owner);
        $crawler = $this->client->request('GET', '/calendario?vista=dia&fecha='.$dayStr);
        $this->client->submit($crawler->filter('form.agenda-check')->first()->form());
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('form.toast__undo')->form());

        self::assertResponseRedirects('/calendario?vista=dia&fecha='.$dayStr);
    }
}
