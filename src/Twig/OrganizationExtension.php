<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Service\OrganizationHierarchy;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Whether the logged-in user leads a department or the whole school ({@see OrganizationHierarchy}) — the
 * same rank {@see \App\Controller\AdminTopicController} gates on, exposed to templates so the nav can
 * show a link to the topics screen only to people who can actually open it.
 */
final class OrganizationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly OrganizationHierarchy $hierarchy,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('leads_a_department', $this->leadsADepartment(...)),
        ];
    }

    /**
     * @return bool whether the logged-in user commands a department or the whole school
     */
    public function leadsADepartment(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User
            && ($this->hierarchy->commandsWholeSchool($user) || null !== $this->hierarchy->commandedDepartment($user));
    }
}
