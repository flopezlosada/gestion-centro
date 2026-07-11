<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants read/write access to a functional {@see Area} based on the user's roles. Use as
 * `denyAccessUnlessGranted(AreaVoter::WRITE, $area)` with any {@see Area} case (e.g. ADMINISTRATION).
 *
 * ROLE_ADMIN (the role's admin flag) bypasses the matrix; everyone else needs a role that grants the
 * required level. This is what lets Direction manage the administration area without being a superuser.
 *
 * @extends Voter<string, Area>
 */
class AreaVoter extends Voter
{
    public const READ = 'AREA_READ';
    public const WRITE = 'AREA_WRITE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::READ, self::WRITE], true) && $subject instanceof Area;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }

        // getRoleNames() reads the roles from the token, honouring any role hierarchy.
        if (\in_array('ROLE_ADMIN', $token->getRoleNames(), true)) {
            return true;
        }

        $required = self::WRITE === $attribute ? PermissionLevel::WRITE : PermissionLevel::READ;

        foreach ($user->getAssignedRoles() as $role) {
            if ($role->allows($subject, $required)) {
                return true;
            }
        }

        return false;
    }
}
