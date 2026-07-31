<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Department;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\DeliverableRequirement;
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
     * Seeds a task due today, assigned to its owner, with the two flags that decide the marker.
     *
     * Both are set EXPLICITLY because the type does not imply them: `requiresDocument` defaults to false
     * whatever the TaskType is ({@see Task}), so building a WITH_DELIVERABLE task and expecting a
     * document flag would test nothing — the row would render the ordinary tickable circle.
     *
     * @param bool $document whether the task must be closed by handing in a document
     * @param bool $checkbox whether the task declares a progress checkbox at all
     *
     * @return User the owner, to be logged in by the caller
     */
    private function seed(bool $document, bool $checkbox = true): User
    {
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $owner = (new User())->setFullName('Profe Test')->setEmail('profe@centro.test')->setUnit($dept);
        $this->em->persist($owner);
        $today = new \DateTimeImmutable('today');
        $type = $document ? TaskType::WITH_DELIVERABLE : TaskType::SIMPLE;
        $task = new Task('Memoria del departamento', SchoolYear::current($today), $today, $type);
        $task->setAssignedUser($owner)->setCreatedBy($owner)
            ->setDeliverable($document ? DeliverableRequirement::LINK : DeliverableRequirement::NONE)
            ->setRequiresCheckbox($checkbox);
        $this->em->persist($task);
        $this->em->flush();

        return $owner;
    }

    public function testADeliverableTaskShowsAClipInsteadOfADeadCircle(): void
    {
        $this->client->loginUser($this->seed(document: true));

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
        $this->client->loginUser($this->seed(document: true));

        $crawler = $this->client->request('GET', '/');

        $label = (string) $crawler->filter('.tick--doc')->attr('aria-label');
        self::assertStringContainsString('Memoria del departamento', $label, 'nombra la tarea de la que habla');
        self::assertStringContainsString('entregando el documento', $label, 'dice por qué no se marca aquí');
    }

    public function testAnOrdinaryTaskStillGetsItsTickableCircle(): void
    {
        // The other half of the contract: nothing changed for a task that DOES close with the tick.
        $this->client->loginUser($this->seed(document: false));

        $this->client->request('GET', '/');

        self::assertSelectorExists('form.tasklist__check-form');
        self::assertSelectorNotExists('.tick--doc');
        self::assertSelectorNotExists('.tasklist__go');
    }

    public function testATaskWithNeitherCheckboxNorDocumentDoesNotClaimToNeedOne(): void
    {
        // "No tick" is not the same as "needs a document": a row with neither must not show the clip, or
        // the marker would promise a deliverable that does not exist. This state cannot be asked for in
        // the form, but rows in the wild carry it (editing a document task used to write
        // requires_checkbox = 0), so the screen has to tell it apart.
        $this->client->loginUser($this->seed(document: false, checkbox: false));

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorNotExists('form.tasklist__check-form', 'no ofrece una casilla que no aplica');
        self::assertSelectorNotExists('.tick--doc', 'no dice que haya que adjuntar nada');
        self::assertSelectorExists('.tick--elsewhere');
        self::assertStringNotContainsString(
            'documento',
            (string) $crawler->filter('.tick--elsewhere')->attr('aria-label'),
            'el marcador no menciona un entregable que esta tarea no tiene',
        );
    }
}
