<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Service\AppSettings;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Whether an optional module is turned on for the centre (Datos del centro). Use as a class-level
 * `#[IsGranted(ModuleVoter::X, statusCode: 404)]` on the module's controller and as `is_granted(...)`
 * for its menu link, so the link and the pages can never disagree.
 *
 * It answers for everybody alike, admins included: a module that is off is off, and the way to look at
 * it is to turn it on.
 *
 * @extends Voter<string, null>
 */
final class ModuleVoter extends Voter
{
    /** The activities open to families. */
    public const FAMILY_ACTIVITIES = 'MODULE_FAMILY_ACTIVITIES';

    public function __construct(private readonly AppSettings $settings)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::FAMILY_ACTIVITIES === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        return $this->settings->isFamilyActivitiesEnabled();
    }
}
