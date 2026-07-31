<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Department;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskComment;
use App\Entity\User;
use App\Enum\DeliverableRequirement;
use App\Enum\TaskType;
use App\Support\TaskStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La ficha de una tarea, vista por cada parte (handoff catalogo/handoff_detalle_tarea): quien la lleva
 * entrega, quien supervisa decide y quien la delegó hace seguimiento. Lo que se comprueba aquí es que la
 * pantalla ofrece la acción de QUIEN mira —arriba, en su tarjeta— y que no ofrece las de los demás.
 *
 * Los campos de cada acción viven dentro de su panel plegado, así que se apunta a los formularios por su
 * ACCIÓN (la ruta de la transición), que es el contrato, y no por clases de maquetación.
 */
final class TaskDetailDecisionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email, ?Department $unit = null): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email);
        if (null !== $unit) {
            $user->setUnit($unit);
        }
        $this->em->persist($user);

        return $user;
    }

    /**
     * A department with a head (ranked role) and a plain member, which is the smallest chart where a
     * verdict is possible: somebody has to outrank the task.
     *
     * @return array{unit: Department, boss: User, member: User}
     */
    private function chart(): array
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $headRole = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
        $this->em->persist($headRole);
        $boss = $this->user('jefa@centro.test', $unit);
        $boss->addAssignedRole($headRole);
        $member = $this->user('profe@centro.test', $unit);

        return ['unit' => $unit, 'boss' => $boss, 'member' => $member];
    }

    private function task(Department $unit, User $assignee, string $status = TaskStatus::PENDING): Task
    {
        $task = new Task('Memoria del departamento', '2025-2026', new \DateTimeImmutable('+20 days'), TaskType::WITH_DELIVERABLE);
        $task->setUnit($unit)->setAssignedUser($assignee)->setDeliverable(DeliverableRequirement::LINK)->setStatus($status);
        $this->em->persist($task);

        return $task;
    }

    public function testWhoeverHoldsThePendingTaskIsToldItIsTheirMove(): void
    {
        $c = $this->chart();
        $task = $this->task($c['unit'], $c['member']);
        $this->em->flush();

        $this->client->loginUser($c['member']);
        $this->client->request('GET', '/tareas/'.$task->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.decision__label', 'Te toca a ti');
        self::assertSelectorExists('form[action$="/accion/submit"]');
        // El enlace se pide DENTRO del panel de la acción, no en un campo abierto en mitad de la columna.
        self::assertSelectorExists('form[action$="/accion/submit"] details input[name="reference"]');
        // La ficha dice quién va a validar, y el ciclo de vida dónde está.
        self::assertSelectorTextContains('.meta-grid', 'Valida');
        self::assertSelectorTextContains('.lifecycle', 'Pendiente de entrega');
    }

    public function testTheSuperiorOfADeliveredTaskGetsTheVerdictAndNotTheDelivery(): void
    {
        $c = $this->chart();
        $task = $this->task($c['unit'], $c['member'], TaskStatus::SUBMITTED);
        $task->setDeliverableReference('https://cloud.educa.madrid.org/memoria');
        $this->em->flush();

        $this->client->loginUser($c['boss']);
        $this->client->request('GET', '/tareas/'.$task->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.decision__label', 'Tu decisión');
        self::assertSelectorExists('form[action$="/accion/validate"]');
        self::assertSelectorExists('form[action$="/accion/review"]');
        // El motivo de la devolución es obligatorio y se pide al pulsar, no antes.
        self::assertSelectorExists('form[action$="/accion/review"] details textarea[required]');
        self::assertSelectorNotExists('form[action$="/accion/submit"]', 'quien supervisa no entrega el trabajo de otro');
        // A quien decide no se le dice quién valida: ya lo pone su tarjeta. Se mira en la REJILLA y no en
        // toda la página, donde "Validar y finalizar" contiene la misma palabra.
        self::assertSelectorTextNotContains('.meta-grid', 'Valida');
    }

    public function testTheReasonItCameBackIsTheFirstThingItsHolderReads(): void
    {
        $c = $this->chart();
        $task = $this->task($c['unit'], $c['member'], TaskStatus::IN_REVIEW);
        $task->setDeliverableReference('https://cloud.educa.madrid.org/memoria');
        $this->em->persist(new TaskComment($task, $c['member'], 'Va la parte de resultados.', 'submit'));
        $this->em->persist(new TaskComment($task, $c['boss'], 'Faltan los datos del tercer trimestre.', 'review'));
        $this->em->flush();

        $this->client->loginUser($c['member']);
        $this->client->request('GET', '/tareas/'.$task->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.returned__body', 'Faltan los datos del tercer trimestre.');
        self::assertSelectorTextContains('.returned__who', 'Jefa Test');
        // Destacado arriba y por tanto FUERA del hilo: la misma frase dos veces se lee como dos mensajes.
        self::assertSelectorTextNotContains('.thread', 'Faltan los datos del tercer trimestre.');
        self::assertSelectorTextContains('.thread', 'Va la parte de resultados.');
        self::assertSelectorTextContains('.detail-main', 'Tu entrega anterior');
    }

    public function testWhoeverDelegatedItFollowsUpInsteadOfBeingOfferedAVerdictOnNothing(): void
    {
        $c = $this->chart();
        $task = $this->task($c['unit'], $c['boss']);
        $task->setDelegatedTo($c['member']);
        $this->em->flush();

        $this->client->loginUser($c['boss']);
        $this->client->request('GET', '/tareas/'.$task->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.decision__label', 'Haces seguimiento');
        // Quién la lleva ahora es un hecho de la tarea, con su cara y su nombre, no un desplegable suelto.
        self::assertSelectorTextContains('.doer__name', 'Profe Test');
        self::assertSelectorExists('form[action$="/recordar"]');
        // Cerrarla sin entrega sigue estando, pero detrás de un separador y con su advertencia.
        self::assertSelectorExists('.decision__sep');
        self::assertSelectorTextContains('.decision', 'Se cierra sin esperar entrega');
    }

    public function testAFinishedTaskIsAReceiptWithItsClosingRemarkAndNoOpenFields(): void
    {
        $c = $this->chart();
        $task = $this->task($c['unit'], $c['member'], TaskStatus::VALIDATED);
        $task->setDeliverableReference('https://cloud.educa.madrid.org/memoria');
        $this->em->persist(new TaskComment($task, $c['boss'], 'Presentado en el claustro del 13.', 'validate'));
        $this->em->flush();

        $this->client->loginUser($c['member']);
        $this->client->request('GET', '/tareas/'.$task->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.decision__closed-title', 'Cerrada y validada');
        self::assertSelectorTextContains('.quote-card--quoted', 'Presentado en el claustro del 13.');
        self::assertSelectorNotExists('.thread__form', 'una tarea cerrada no admite más conversación');
        self::assertSelectorNotExists('form[action$="/accion/submit"]');
        // Y sigue diciendo cuándo se cerró, que es lo que se viene a buscar meses después.
        self::assertSelectorTextContains('.meta-grid', 'Cerrada');
    }

    public function testTheHistoryIsStillThereButFoldedAwayFromTheDecision(): void
    {
        $c = $this->chart();
        $task = $this->task($c['unit'], $c['member']);
        $this->em->flush();

        $this->client->loginUser($c['member']);
        $this->client->request('GET', '/tareas/'.$task->getId());

        self::assertResponseIsSuccessful();
        // El log campo a campo es para auditar: plegado, al final y dentro de un <details>.
        self::assertSelectorExists('details.task-history .obj-timeline');
    }
}
