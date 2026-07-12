<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Support\TaskActivityPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the activity presenter: humanised labels/values, create/delete collapsing to a
 * summary line, relation ids and actor e-mails resolved to names in a single batched query, and the
 * raw diff kept intact for the admin detail.
 */
final class TaskActivityPresenterTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $changes
     */
    private function entry(string $action, ?array $changes, ?string $actor = null): AuditLog
    {
        return new AuditLog($action, $actor, 'Task', '1', null, $changes);
    }

    /** An entity manager that must never hit the database (no refs, no named actors). */
    private function emWithNoLookups(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');

        return $em;
    }

    /**
     * An entity manager whose repository lookups all return the given entities.
     *
     * @param object[] $entities the entities every findBy() resolves to
     */
    private function emReturning(array $entities): EntityManagerInterface
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn($entities);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return $em;
    }

    private function user(string $fullName, string $email, ?int $id = null): User
    {
        $user = (new User())->setFullName($fullName)->setEmail($email);
        if (null !== $id) {
            (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        }

        return $user;
    }

    public function testUpdateHumanisesLabelsAndValues(): void
    {
        // No refs; the only lookup is the (unresolved) actor, which falls back to its e-mail.
        $presenter = new TaskActivityPresenter($this->emReturning([]));

        $rows = $presenter->present([
            $this->entry('task.updated', [
                'dueDate' => ['old' => '2026-07-13T00:00:00+02:00', 'new' => '2026-07-14T00:00:00+02:00'],
                'requiresDocument' => ['old' => false, 'new' => true],
                'status' => ['old' => 'pending', 'new' => 'in_progress'],
                'type' => ['old' => 'simple', 'new' => 'with_deliverable'],
            ], 'director@centro.test'),
        ]);

        self::assertCount(1, $rows);
        self::assertSame('Modificación', $rows[0]['verbLabel']);
        self::assertSame('director@centro.test', $rows[0]['actor'], 'an unresolved actor keeps its e-mail');
        self::assertNull($rows[0]['summary']);
        self::assertSame([
            ['label' => 'Fecha límite', 'old' => '13/07/2026', 'new' => '14/07/2026'],
            ['label' => 'Requiere documento', 'old' => 'No', 'new' => 'Sí'],
            ['label' => 'Estado', 'old' => 'Pendiente', 'new' => 'En curso'],
            ['label' => 'Tipo', 'old' => 'Tarea simple', 'new' => 'Tarea con entregable'],
        ], $rows[0]['changes']);
    }

    public function testNullValueRendersAsPlaceholder(): void
    {
        $presenter = new TaskActivityPresenter($this->emWithNoLookups());

        $rows = $presenter->present([
            $this->entry('task.updated', ['description' => ['old' => null, 'new' => 'Redactar el acta.']]),
        ]);

        self::assertSame([['label' => 'Descripción', 'old' => '—', 'new' => 'Redactar el acta.']], $rows[0]['changes']);
    }

    public function testCreationCollapsesToSummaryWithTitle(): void
    {
        $presenter = new TaskActivityPresenter($this->emWithNoLookups());

        $rows = $presenter->present([
            $this->entry('task.created', [
                'title' => ['old' => null, 'new' => 'Preparar el acta de la CCP'],
                'status' => ['old' => null, 'new' => 'pending'],
                'mandatory' => ['old' => null, 'new' => true],
            ]),
        ]);

        self::assertSame('Creación', $rows[0]['verbLabel']);
        self::assertNull($rows[0]['actor'], 'a system event has no actor');
        self::assertSame('Se creó la tarea «Preparar el acta de la CCP».', $rows[0]['summary']);
        self::assertSame([], $rows[0]['changes'], 'a creation must not list every field');
    }

    public function testDeletionCollapsesToSummary(): void
    {
        $presenter = new TaskActivityPresenter($this->emWithNoLookups());

        $rows = $presenter->present([
            $this->entry('task.deleted', ['title' => ['old' => 'Vieja tarea', 'new' => null]]),
        ]);

        self::assertSame('Baja', $rows[0]['verbLabel']);
        self::assertSame('Se dio de baja la tarea «Vieja tarea».', $rows[0]['summary']);
    }

    public function testUnmappedFieldIsHiddenFromFriendlyButKeptInRaw(): void
    {
        $presenter = new TaskActivityPresenter($this->emWithNoLookups());

        $changes = ['internalFlag' => ['old' => 1, 'new' => 2]];
        $rows = $presenter->present([$this->entry('task.updated', $changes)]);

        self::assertSame([], $rows[0]['changes'], 'fields without a mapping are not shown to non-technical readers');
        self::assertSame($changes, $rows[0]['raw'], 'but the raw diff is kept intact for the admin detail');
    }

    public function testReferenceIdsAreResolvedToNamesInOneQueryPerType(): void
    {
        $ana = $this->user('Ana Ruiz', 'ana@centro.test', 7);
        $luis = $this->user('Luis Gil', 'luis@centro.test', 9);

        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::once())->method('findBy')->willReturn([$ana, $luis]);

        $em = $this->createMock(EntityManagerInterface::class);
        // A single getRepository(User) call proves the two ids are batched, not fetched one by one;
        // the actor is null here, so it adds no extra lookup.
        $em->expects(self::once())->method('getRepository')->with(User::class)->willReturn($repo);

        $presenter = new TaskActivityPresenter($em);

        $rows = $presenter->present([
            $this->entry('task.updated', ['assignedUser' => ['old' => 7, 'new' => 9]]),
        ]);

        self::assertSame([['label' => 'Responsable', 'old' => 'Ana Ruiz', 'new' => 'Luis Gil']], $rows[0]['changes']);
    }

    public function testMissingReferenceFallsBackToDeletedMarker(): void
    {
        $presenter = new TaskActivityPresenter($this->emReturning([]));

        $rows = $presenter->present([
            $this->entry('task.updated', ['assignedUser' => ['old' => null, 'new' => 42]]),
        ]);

        self::assertSame([['label' => 'Responsable', 'old' => '—', 'new' => '#42 (eliminado)']], $rows[0]['changes']);
    }

    public function testActorEmailIsResolvedToFullName(): void
    {
        $presenter = new TaskActivityPresenter($this->emReturning([$this->user('María Directora', 'director@centro.test')]));

        $rows = $presenter->present([
            $this->entry('task.updated', ['title' => ['old' => 'A', 'new' => 'B']], 'director@centro.test'),
        ]);

        self::assertSame('María Directora', $rows[0]['actor']);
    }
}
