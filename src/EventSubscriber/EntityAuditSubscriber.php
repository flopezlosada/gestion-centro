<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Contract\Auditable;
use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Captures every insert, update and delete of {@see Auditable} entities into the activity trail
 * ({@see AuditLog}) with a field-level before/after diff, without instrumenting the call sites.
 *
 * The change set is collected during {@see Events::onFlush} (where the Unit of Work still exposes
 * the diff) and the log rows are written in {@see Events::postFlush} — after the business flush, so
 * generated identifiers are available for inserts and we never push half-finished work. A reentrancy
 * guard stops the log-writing flush from recursing.
 *
 * Known limitation: many-to-many / collection changes (e.g. a user's role set) are not diffed yet;
 * only mapped scalar and to-one fields are. Collection tracking is a documented follow-up.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class EntityAuditSubscriber
{
    /** Property names whose values are never written to the diff. */
    private const array REDACTED = ['password', 'plainPassword', 'token', 'secret'];

    /**
     * Entities scheduled in the current flush, awaiting their log row in postFlush.
     *
     * @var list<array{entity: object, action: string, changes: array<string, array{old: mixed, new: mixed}>|null}>
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

        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Auditable) {
                $this->pending[] = ['entity' => $entity, 'action' => 'created', 'changes' => $this->diff($uow->getEntityChangeSet($entity))];
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Auditable) {
                continue;
            }
            $changes = $this->diff($uow->getEntityChangeSet($entity));
            if (null !== $changes) {
                $this->pending[] = ['entity' => $entity, 'action' => 'updated', 'changes' => $changes];
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof Auditable) {
                $this->pending[] = ['entity' => $entity, 'action' => 'deleted', 'changes' => null];
            }
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
                $meta = $em->getClassMetadata($record['entity']::class);
                $ids = $meta->getIdentifierValues($record['entity']);
                $shortName = $meta->getReflectionClass()->getShortName();

                $em->persist(new AuditLog(
                    action: sprintf('%s.%s', $this->slug($shortName), $record['action']),
                    actor: $actor,
                    subjectType: $shortName,
                    subjectId: [] === $ids ? null : implode('-', array_map(strval(...), $ids)),
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
     * Turns a Doctrine change set into a JSON-safe before/after diff, or null when there is nothing
     * to record.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet Doctrine field change set
     *
     * @return array<string, array{old: mixed, new: mixed}>|null the normalised diff, or null if empty
     */
    private function diff(array $changeSet): ?array
    {
        $diff = [];
        foreach ($changeSet as $field => [$old, $new]) {
            if (\in_array($field, self::REDACTED, true)) {
                $diff[$field] = ['old' => '***', 'new' => '***'];
                continue;
            }
            $diff[$field] = ['old' => $this->normalize($old), 'new' => $this->normalize($new)];
        }

        return [] === $diff ? null : $diff;
    }

    /**
     * Reduces a change-set value to something JSON-serialisable: scalars as-is, dates to ATOM
     * strings, enums to their value/name, associated entities to their id, arrays recursively.
     */
    private function normalize(mixed $value): mixed
    {
        return match (true) {
            null === $value, \is_scalar($value) => $value,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            \is_array($value) => array_map($this->normalize(...), $value),
            \is_object($value) && method_exists($value, 'getId') => $value->getId(),
            \is_object($value) => $value::class,
            default => (string) $value,
        };
    }

    /** Converts an entity short name to a snake_case action prefix ("TaskTemplate" → "task_template"). */
    private function slug(string $shortName): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));
    }
}
