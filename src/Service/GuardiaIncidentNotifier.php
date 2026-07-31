<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GuardiaCover;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\Area;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;

/**
 * Tells the guardia coordination that something happened during a cover, the moment the person covering
 * it says so.
 *
 * It exists because the parte was the ONLY place an incident could be recorded, and only the coordination
 * could write there: whoever was actually standing in the classroom had to find them in person to get it
 * on the record, so most incidents never made it. The note travels with the notice, because an "hay una
 * incidencia" that forces you to open the screen to find out what happened is a second errand.
 *
 * The absent teacher is deliberately NOT notified: what happened with their group is the coordination's
 * business to resolve, and they may be away precisely because they are ill.
 */
final class GuardiaIncidentNotifier
{
    /** Machine kind of the notice; it deep-links to the cover through the notification's task-less path. */
    public const string KIND = 'guardia.incident';

    public function __construct(
        private readonly RoleRepository $roles,
        private readonly UserRepository $users,
        private readonly NotificationDispatcher $dispatcher,
    ) {
    }

    /**
     * Notifies the coordination about the incident just reported on a cover. Does nothing when there is
     * no note (nothing to tell) or nobody to tell.
     *
     * @param GuardiaCover $cover    the cover the incident is about
     * @param User         $reporter who reported it, so the notice says who to ask
     *
     * @return list<User> the people notified
     */
    public function notifyReported(GuardiaCover $cover, User $reporter): array
    {
        $note = $cover->getIncidentNote();
        if (null === $note) {
            return [];
        }

        $recipients = [];
        foreach ($this->roles->findWritersOf(Area::GUARDIAS) as $role) {
            foreach ($this->users->findActiveByRole($role) as $user) {
                // Quien reporta no se avisa a sí mismo: la coordinación también cubre guardias.
                if ($user->getId() !== $reporter->getId()) {
                    $recipients[(int) $user->getId()] = $user;
                }
            }
        }
        if ([] === $recipients) {
            return [];
        }

        $notifications = [];
        foreach ($recipients as $recipient) {
            $notifications[] = $this->dispatcher->record(
                $recipient,
                self::KIND,
                sprintf('Incidencia en una guardia del %s', $cover->getDate()->format('d/m/Y')),
                sprintf(
                    '%s%s: %s',
                    $reporter->getFullName(),
                    null !== $cover->getGroupName() ? sprintf(' (%s)', $cover->getGroupName()) : '',
                    $note,
                ),
            );
        }
        $this->dispatcher->flushAndSend($notifications);

        return array_values($recipients);
    }
}
