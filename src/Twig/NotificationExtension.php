<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the current user's unread-notification count to templates, for the inbox badge in the nav.
 */
final class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly NotificationRepository $notifications,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications', $this->unreadCount(...)),
        ];
    }

    /**
     * The number of unread notifications for the logged-in user (0 when nobody is logged in).
     *
     * @return int the unread count
     */
    public function unreadCount(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->notifications->countUnreadFor($user) : 0;
    }
}
