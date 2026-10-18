<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\FamilyVolunteer;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * How the activity trail names whoever did something.
 *
 * Staff by their e-mail address, as always. A family by its account number only: the trail is kept, and
 * a removed family account must take its name and address with it (see {@see FamilyVolunteer}).
 */
final class AuditActor
{
    /**
     * @param UserInterface|null $account who did it, or null when nobody is signed in
     *
     * @return string|null the name the trail keeps, or null when nobody is signed in
     */
    public static function of(?UserInterface $account): ?string
    {
        return match (true) {
            null === $account => null,
            $account instanceof FamilyVolunteer => 'familia #'.$account->getId(),
            default => $account->getUserIdentifier(),
        };
    }
}
