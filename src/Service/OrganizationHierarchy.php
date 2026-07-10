<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Unit;
use App\Entity\User;

/**
 * Walks the chain of command built from {@see Unit::getParent()} / {@see Unit::getManager()}. The
 * single source of truth for "who is above whom", used both for validation (only a superior may
 * validate) and for escalation of reminders up the hierarchy.
 *
 * Operates purely on the entity graph (no database), so it is cheap and unit-testable in isolation.
 */
final class OrganizationHierarchy
{
    /**
     * The managers found walking up from a unit to the top of the chain, nearest first. The unit's
     * own manager comes first, then its parent's, and so on. Duplicates and cycles are guarded.
     *
     * @param Unit|null $unit the starting unit (null yields an empty chain)
     *
     * @return list<User> the managers up the chain, nearest first
     */
    public function managersAbove(?Unit $unit): array
    {
        $managers = [];
        $seenUnits = [];
        $seenManagers = [];

        while (null !== $unit && !isset($seenUnits[spl_object_id($unit)])) {
            $seenUnits[spl_object_id($unit)] = true;

            $manager = $unit->getManager();
            if (null !== $manager && !isset($seenManagers[spl_object_id($manager)])) {
                $seenManagers[spl_object_id($manager)] = true;
                $managers[] = $manager;
            }

            $unit = $unit->getParent();
        }

        return $managers;
    }

    /**
     * Whether the given user is a superior of the given unit: the manager of that unit or of any
     * unit above it in the chain of command.
     *
     * @param User      $actor the user to check
     * @param Unit|null $unit  the unit whose chain is walked (null → no superior can be determined)
     *
     * @return bool true if the actor manages the unit or an ancestor of it
     */
    public function isSuperiorOf(User $actor, ?Unit $unit): bool
    {
        foreach ($this->managersAbove($unit) as $manager) {
            // Identity (===) is safe here: managers and the actor come from the same EntityManager
            // within a request, so Doctrine's identity map guarantees one PHP object per row. Do NOT
            // "unify" this with User::holdsRole()'s compare-by-id — that pattern exists there for
            // possibly-unpersisted roles; here it would break the in-memory tests.
            if ($manager === $actor) {
                return true;
            }
        }

        return false;
    }
}
