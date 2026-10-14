<?php

declare(strict_types=1);

namespace App\Service\Cloud;

use App\Entity\CloudShare;
use App\Entity\Meeting;
use App\Entity\User;
use App\Repository\CloudShareRepository;
use App\Service\Cron\TaskLock;
use App\Service\MeetingNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Shares the actas' documents in the EducaMadrid cloud as the cloud allows: it lets one account create
 * only 20 shares every 10 minutes, and a claustro is some 80 people. Every share is queued
 * ({@see CloudShare}); what fits is made at once, and the scheduled task makes the rest a few minutes
 * later, until nobody is left.
 *
 * Whoever is told "ya puedes leer el acta" is told when THEIR share is made, not when the acta is
 * published: told before, they would open a link the cloud does not let them see yet.
 *
 * Who it is shared with goes through CLOUD_SHARE_WITH, and the default is NOBODY: "all" in production, a
 * comma-separated list of cloud logins anywhere else. A development database holds the real staff list,
 * and sharing a document makes the cloud notify a real person — so forgetting to configure it must fail
 * silent, not loud.
 */
final class CloudShareQueue
{
    private const string EDUCAMADRID_DOMAIN = '@educa.madrid.org';

    /**
     * The queue is worked from two places — the scheduled task and whoever publishes — and two passes at
     * once would both make the same share, the second one reading "already shared" as a failure.
     */
    private const string LOCK = 'cloud_share_queue';

    public function __construct(
        private readonly CloudAccount $account,
        private readonly EducamadridCloud $cloud,
        private readonly CloudShareRepository $shares,
        private readonly EntityManagerInterface $entityManager,
        private readonly MeetingNotifier $notifier,
        private readonly TaskLock $lock,
        #[Autowire(env: 'default::CLOUD_SHARE_WITH')]
        private readonly ?string $shareWith,
    ) {
    }

    /**
     * Queues a document to be shared with these people, replacing whatever was left of the meeting's
     * shares with the same permissions: those pointed at the document this one replaces (a correction
     * published again) or are being queued again from scratch. Who cannot be shared with at all is queued
     * as given up, with the reason, so it shows.
     *
     * @param Meeting    $meeting     the meeting the document belongs to
     * @param string     $fileId      the document's id in the cloud
     * @param list<User> $people      who gets it
     * @param int        $permissions {@see EducamadridCloud::PERMISSION_READ} or PERMISSION_EDIT
     * @param bool       $notify      whether each is told once it is shared with them
     */
    public function enqueue(Meeting $meeting, string $fileId, array $people, int $permissions, bool $notify): void
    {
        foreach ($this->shares->findForMeeting($meeting) as $old) {
            if ($old->getPermissions() === $permissions) {
                $this->entityManager->remove($old);
            }
        }
        // Se borran antes de añadir: la nueva fila de la misma persona y el mismo documento chocaría con la
        // vieja en el índice único.
        $this->entityManager->flush();

        $owner = strtolower((string) $this->account->credentials()?->login);
        $allowed = $this->allowedLogins();
        $now = new \DateTimeImmutable();
        $queued = [];
        foreach ($people as $person) {
            $login = self::cloudLoginOf($person);
            if ($login === $owner || isset($queued[spl_object_id($person)])) {
                continue;
            }
            $queued[spl_object_id($person)] = true;
            $share = new CloudShare($meeting, $person, $fileId, $permissions, $notify, $now);
            if (null === $login) {
                $share->fail('no tiene correo de EducaMadrid');
            } elseif (null !== $allowed && !\in_array($login, $allowed, true)) {
                $share->fail('fuera de CLOUD_SHARE_WITH (entorno de pruebas)');
            }
            $this->entityManager->persist($share);
        }
        $this->entityManager->flush();
    }

    /**
     * Makes the pending shares, oldest first, until none is left or the cloud says "no more for now".
     * Each one is saved as it is made, so stopping halfway loses nothing and tells nobody twice.
     *
     * @return CloudShareRun how many were made and given up on, and why it stopped early if it did
     */
    public function process(): CloudShareRun
    {
        if (!$this->lock->acquire(self::LOCK)) {
            return new CloudShareRun(0, 0, 'Otra pasada está compartiendo en este momento; lo que quede lo hará ella o la siguiente.', throttled: true);
        }
        try {
            return $this->work();
        } finally {
            $this->lock->release(self::LOCK);
        }
    }

    /**
     * {@see process()}, holding the lock.
     */
    private function work(): CloudShareRun
    {
        $credentials = $this->account->credentials();
        $pending = $this->shares->findPending();
        if (null === $credentials || [] === $pending) {
            return new CloudShareRun(0, 0, null === $credentials && [] !== $pending ? 'La nube de EducaMadrid no está conectada.' : null);
        }

        $byFile = [];
        foreach ($pending as $share) {
            $byFile[$share->getFileId()][] = $share;
        }

        $shared = 0;
        $failed = 0;
        try {
            foreach ($byFile as $fileId => $shares) {
                $path = $this->cloud->pathOf($credentials, (string) $fileId);
                $already = null === $path ? [] : $this->cloud->sharedWith($credentials, $path);
                foreach ($shares as $share) {
                    $login = self::cloudLoginOf($share->getPerson());
                    $problem = match (true) {
                        null === $path => 'el documento ya no está en la nube: puede que lo hayan borrado',
                        null === $login => 'no tiene correo de EducaMadrid',
                        \in_array($login, $already, true) => null,
                        default => $this->cloud->share($credentials, $path, $login, $share->getPermissions()),
                    };
                    if (null === $problem) {
                        $this->made($share);
                        ++$shared;
                    } else {
                        $share->fail($problem);
                        $this->entityManager->flush();
                        ++$failed;
                    }
                }
            }
        } catch (CloudRateLimitedException $e) {
            return new CloudShareRun($shared, $failed, $e->getMessage(), throttled: true);
        } catch (CloudException $e) {
            // Ni la persona ni el documento tienen la culpa (la nube no deja más o no contesta): lo que
            // queda sigue pendiente para la próxima vuelta.
            return new CloudShareRun($shared, $failed, $e->getMessage());
        }

        return new CloudShareRun($shared, $failed, null);
    }

    /**
     * What is left of a meeting's shares, for the screen: how many are still to be made, and who was left
     * out and why.
     *
     * @return array{pending: int, problems: list<string>}
     */
    public function leftFor(Meeting $meeting): array
    {
        $left = $this->shares->findForMeeting($meeting);

        return [
            'pending' => \count(array_filter($left, static fn (CloudShare $s): bool => $s->isPending())),
            'problems' => array_values(array_map(
                static fn (CloudShare $s): string => $s->getPerson()->getFullName().': '.$s->getProblem(),
                array_filter($left, static fn (CloudShare $s): bool => !$s->isPending()),
            )),
        ];
    }

    /**
     * A person's login in the EducaMadrid cloud: their e-mail's local part, when the e-mail is an
     * EducaMadrid one (checked against a real account: natalia.x@educa.madrid.org logs in as natalia.x).
     *
     * @return string|null the login, or null for somebody without an EducaMadrid e-mail
     */
    public static function cloudLoginOf(User $user): ?string
    {
        $email = strtolower($user->getEmail());

        return str_ends_with($email, self::EDUCAMADRID_DOMAIN) ? substr($email, 0, -\strlen(self::EDUCAMADRID_DOMAIN)) : null;
    }

    /**
     * Done: the row goes, and the person is told now that they can open it.
     */
    private function made(CloudShare $share): void
    {
        $this->entityManager->remove($share);
        $this->entityManager->flush();
        if ($share->notifies()) {
            $this->notifier->notifyMinutes($share->getMeeting(), [$share->getPerson()]);
        }
    }

    /**
     * @return list<string>|null the logins it may be shared with, or null for everybody
     */
    private function allowedLogins(): ?array
    {
        $setting = trim((string) $this->shareWith);

        return 'all' === $setting ? null : array_values(array_filter(array_map(
            static fn (string $l): string => strtolower(trim($l)),
            explode(',', $setting),
        )));
    }
}
