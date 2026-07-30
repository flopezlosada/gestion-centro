<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\GuardiaCover;
use App\Entity\User;
use App\Enum\Area;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Who may act on the WORK of one guardia: pull a task from the bank for it, drop it, or order its
 * copies. Use as `denyAccessUnlessGranted(GuardiaCoverVoter::WORK_ON_TASK, $cover)` and, in a template,
 * as `is_granted(...)` — so the buttons a screen paints and the actions a controller accepts can never
 * drift apart.
 *
 * The rule is the one the centre works by: the guardia teacher covering it (it is their class in an
 * hour), the absent teacher (it is their group), or whoever coordinates guardias. Deliberately NOT the
 * same as reading the parte: read access to the area is enough to look, never to touch someone else's
 * class. The private reason for the absence is not gated here — that one lives on {@see \App\Entity\Absence}
 * and is only ever shown to the coordination.
 *
 * @extends Voter<string, GuardiaCover>
 */
class GuardiaCoverVoter extends Voter
{
    public const WORK_ON_TASK = 'GUARDIA_COVER_WORK_ON_TASK';

    public function __construct(private readonly AccessDecisionManagerInterface $accessDecisionManager)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::WORK_ON_TASK === $attribute && $subject instanceof GuardiaCover;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }

        if ($subject->getAssignedGuardia()?->getId() === $user->getId()) {
            return true;
        }
        if ($subject->getAbsentTeacher()->getId() === $user->getId()) {
            return true;
        }

        // The coordination may act on any guardia: it is the surface they work the whole period from.
        return $this->accessDecisionManager->decide($token, [AreaVoter::WRITE], Area::GUARDIAS);
    }
}
