<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The data-protection information the staff must read (art. 13 GDPR), proved by a {@see PrivacyNoticeAck}.
 *
 * It is information, not consent: a public school processes its staff's data under a legal obligation
 * and a public-interest task, so there is nothing to "accept" — only proof that people were informed.
 */
#[ORM\Entity]
class StaffPrivacyNotice extends PrivacyNotice
{
}
