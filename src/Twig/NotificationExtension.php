<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Support\NotificationLink;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Notification helpers for templates: the current user's unread count (the inbox badge in the nav) and
 * where a notice leads, so the inbox row and the push notification for the same notice always agree.
 */
final class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly NotificationRepository $notifications,
        private readonly NotificationLink $link,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications', $this->unreadCount(...)),
            new TwigFunction('notification_path', $this->pathFor(...)),
        ];
    }

    /**
     * Where a notice leads when clicked in the inbox — the very path its push notification opens
     * ({@see NotificationLink}).
     *
     * @param Notification $notification the notice
     *
     * @return string the in-app path to open
     */
    public function pathFor(Notification $notification): string
    {
        return $this->link->pathFor($notification);
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
