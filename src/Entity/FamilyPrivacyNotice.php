<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The data-protection information for the families who volunteer, accepted with a {@see FamilyConsent}.
 *
 * Unlike the staff's, this one IS a request for consent (art. 6.1.a GDPR): volunteering is free, so the
 * family may say no — and saying no, or withdrawing later, removes the account and its data.
 */
#[ORM\Entity]
class FamilyPrivacyNotice extends PrivacyNotice
{
}
