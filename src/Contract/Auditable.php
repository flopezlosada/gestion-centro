<?php

declare(strict_types=1);

namespace App\Contract;

/**
 * Marker interface for entities whose every insert, update and delete must be captured
 * automatically in the activity trail ({@see \App\Entity\AuditLog}).
 *
 * Implementing this (no methods required) opts the entity into field-level change tracking
 * performed by {@see \App\EventSubscriber\EntityAuditSubscriber}. Nothing needs to be
 * instrumented by hand at the call sites.
 */
interface Auditable
{
}
