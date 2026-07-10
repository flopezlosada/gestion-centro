<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Contract\Auditable;
use App\Entity\AuditLog;
use App\Support\ChangeNormalizer;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Captures every insert, update and delete of {@see Auditable} entities into the activity trail
 * ({@see AuditLog}) with a field-level before/after diff, without instrumenting the call sites.
 *
 * Scalar/to-one changes and to-many (incl. many-to-many, e.g. a user's role set) changes are
 * collected during {@see Events::onFlush} — where the Unit of Work still exposes the diff and the
 * identifiers of updated/deleted rows are still present — and merged into a single entry per entity.
 * The rows are written in {@see Events::postFlush}, after the business flush, so a generated
 * identifier is available for inserts and we never push half-finished work. A reentrancy guard stops
 * the log-writing flush from recursing, and {@see $pending} is reset on entry so a failed flush can
 * never leak ghost entries into the next one.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class EntityAuditSubscriber
{
    /**
     * Entries collected in the current flush, awaiting their row in postFlush.
     *
     * @var list<array{entity: object, action: string, changes: array<string, mixed>|null, subjectId: ?string}>
     */
    private array $pending = [];

    /** True while writing log rows, so the resulting flush does not recurse into this listener. */
    private bool $persisting = false;

    public function __construct(private readonly Security $security)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->persisting) {
            return;
        }

        // Never carry over records from a previous flush: if the owning flush threw between onFlush
        // and postFlush, those records describe changes that never hit the database.
        $this->pending = [];

        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        /** @var array<int, array{entity: object, action: string, changes: array<string, mixed>|null, subjectId: ?string}> $records */
        $records = [];

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Auditable) {
                // The generated id is not available yet; it is resolved in postFlush.
                $records[spl_object_id($entity)] = ['entity' => $entity, 'action' => 'created', 'changes' => ChangeNormalizer::diff($uow->getEntityChangeSet($entity)), 'subjectId' => null];
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Auditable) {
                $records[spl_object_id($entity)] = ['entity' => $entity, 'action' => 'updated', 'changes' => ChangeNormalizer::diff($uow->getEntityChangeSet($entity)), 'subjectId' => $this->resolveSubjectId($em, $entity)];
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Auditable) {
                // Resolve the id now: executeDeletions() nulls it before postFlush runs.
                $records[spl_object_id($entity)] = ['entity' => $entity, 'action' => 'deleted', 'changes' => null, 'subjectId' => $this->resolveSubjectId($em, $entity)];
            }
        }

        // To-many changes (incl. M:N such as a user's roles) are merged into the owner's entry. Only
        // the owning side is scheduled, so a change is never counted twice.
        foreach ([...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()] as $collection) {
            $owner = $collection->getOwner();
            if (null === $owner || !$owner instanceof Auditable || !$collection->getMapping()->isOwningSide()) {
                continue;
            }

            $added = ChangeNormalizer::describeAll($collection->getInsertDiff());
            $removed = ChangeNormalizer::describeAll($collection->getDeleteDiff());
            if ([] === $added && [] === $removed) {
                continue;
            }

            $oid = spl_object_id($owner);
            $records[$oid] ??= ['entity' => $owner, 'action' => 'updated', 'changes' => [], 'subjectId' => $this->resolveSubjectId($em, $owner)];
            $changes = $records[$oid]['changes'] ?? [];
            $changes[$collection->getMapping()->fieldName] = ['added' => $added, 'removed' => $removed];
            $records[$oid]['changes'] = $changes;
        }

        // Skip no-op updates (flagged for update but with nothing recordable).
        foreach ($records as $record) {
            if ('updated' === $record['action'] && (null === $record['changes'] || [] === $record['changes'])) {
                continue;
            }
            $this->pending[] = $record;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->persisting || [] === $this->pending) {
            return;
        }

        $records = $this->pending;
        $this->pending = [];
        $em = $args->getObjectManager();
        $actor = $this->security->getUser()?->getUserIdentifier();

        $this->persisting = true;
        try {
            foreach ($records as $record) {
                $entity = $record['entity'];
                $shortName = $em->getClassMetadata($entity::class)->getReflectionClass()->getShortName();
                // Inserts resolve their generated id here; updates/deletes already carry it.
                $subjectId = $record['subjectId'] ?? $this->resolveSubjectId($em, $entity);

                $em->persist(new AuditLog(
                    action: sprintf('%s.%s', $this->slug($shortName), $record['action']),
                    actor: $actor,
                    subjectType: $shortName,
                    subjectId: $subjectId,
                    summary: null,
                    changes: $record['changes'],
                ));
            }
            $em->flush();
        } finally {
            $this->persisting = false;
        }
    }

    /**
     * The entity's identifier as a string ('-'-joined for composite keys), or null when it has none
     * (a not-yet-generated insert id, or a deletion whose id was already cleared).
     */
    private function resolveSubjectId(EntityManagerInterface $em, object $entity): ?string
    {
        $ids = $em->getClassMetadata($entity::class)->getIdentifierValues($entity);
        if ([] === $ids) {
            return null;
        }

        return implode('-', array_map(
            static fn (mixed $id): string => \is_object($id) && method_exists($id, 'getId') ? (string) $id->getId() : (string) $id,
            $ids,
        ));
    }

    /** Converts an entity short name to a snake_case action prefix ("TaskTemplate" → "task_template"). */
    private function slug(string $shortName): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
    }
}
