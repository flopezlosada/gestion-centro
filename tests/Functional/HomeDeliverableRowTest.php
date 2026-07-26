<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Department;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskType;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A task with a deliverable never closes with the one-click tick ({@see Task::requiresCheckbox()}): it
 * is completed by Entregar (with its document) → Validar. Inicio used to show it as a dimmed circle —
 * a control that looks pressable, does nothing, says nothing about why, and was `aria-hidden` so a
 * screen reader did not even mention it.
 *
 * It now gets a marker of a DIFFERENT SHAPE (a clip), which is the convention the calendar already
 * uses to tell things apart (circle = task, square = event, shield = guardia): the shape says what it
 * is, so it does not promise a one-tap close.
 */
final class HomeDeliverableRowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Seeds a task due today, assigned to its owner, of the given type.
     *
     * @param TaskType $type the task type, which decides whether the tick applies
     *
     * @return User the owner, already logged in by the caller
     */
    private function seed(TaskType $type): User
    {
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $owner = (new User())->setFullName('Profe Test')->setEmail('profe@centro.test')->setUnit($dept);
        $this->em->persist($owner);
        $today = new \DateTimeImmutable('today');
        $task = new Task('Memoria del departamento', SchoolYear::current($today), $today, $type);
        $task->setAssignedUser($owner)->setCreatedBy($owner);
        $this->em->persist($task);
        $this->em->flush();

        return $owner;
    }

    public function testADeliverableTaskShowsAClipInsteadOfADeadCircle(): void
    {
        $this->client->loginUser($this->seed(TaskType::WITH_DELIVERABLE));

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.tick--doc');
        // No tick form: this task cannot be closed that way, and offering one would be a lie.
        self::assertSelectorNotExists('form.tasklist__check-form');
        // And it points somewhere: the row leads to the ficha, where Entregar actually lives.
        self::assertSelectorExists('.tasklist__go');
    }

    public function testTheClipExplainsItselfToAScreenReader(): void
    {
        // The regression being pinned: the old marker was aria-hidden with no text at all, so a screen
        // reader user got a row with no hint of why it could not be ticked.
        $this->client->loginUser($this->seed(TaskType::WITH_DELIVERABLE));

        $crawler = $this->client->request('GET', '/');

        $label = (string) $crawler->filter('.tick--doc')->attr('aria-label');
        self::assertStringContainsString('Memoria del departamento', $label, 'nombra la tarea de la que habla');
        self::assertStringContainsString('entregando el documento', $label, 'dice por qué no se marca aquí');
    }

    public function testAnOrdinaryTaskStillGetsItsTickableCircle(): void
    {
        // The other half of the contract: nothing changed for a task that DOES close with the tick.
        $this->client->loginUser($this->seed(TaskType::SIMPLE));

        $this->client->request('GET', '/');

        self::assertSelectorExists('form.tasklist__check-form');
        self::assertSelectorNotExists('.tick--doc');
        self::assertSelectorNotExists('.tasklist__go');
    }
}
