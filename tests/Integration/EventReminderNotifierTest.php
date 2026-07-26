<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\PersonalEvent;
use App\Entity\User;
use App\Enum\EventReminderOffset;
use App\Repository\NotificationRepository;
use App\Service\EventReminderNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The agenda reminder sweep: it must notify each owner about their own entry once and only once, leave
 * alone what is not due (or already under way), and stay off e-mail — a "empieza en 10 minutos" notice
 * is worth nothing in an inbox.
 *
 * Every instant here is built without an explicit zone, i.e. in PHP's default one — the same the sweep
 * and Doctrine use (see {@see EventReminderNotifier}), so the test behaves the same in CI (UTC) and in
 * local (Madrid).
 */
final class EventReminderNotifierTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EventReminderNotifier $notifier;
    private NotificationRepository $notifications;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->notifier = self::getContainer()->get(EventReminderNotifier::class);
        $this->notifications = self::getContainer()->get(NotificationRepository::class);
    }

    private function user(string $email): User
    {
        $user = (new User())->setFullName($email)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    private function event(User $owner, string $start, ?EventReminderOffset $reminder): PersonalEvent
    {
        $event = new PersonalEvent($owner, 'Claustro', new \DateTimeImmutable($start));
        $event->setReminder($reminder);
        $this->em->persist($event);

        return $event;
    }

    public function testTheOwnerIsNotifiedWhenTheReminderComesDue(): void
    {
        $owner = $this->user('profe@centro.test');
        $event = $this->event($owner, '2026-09-15 10:00', EventReminderOffset::TEN_MINUTES);
        $this->em->flush();

        $sent = $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 09:52'));

        self::assertSame(1, $sent);
        $notice = $this->notifications->findRecentFor($owner)[0] ?? null;
        self::assertNotNull($notice);
        self::assertSame('event.reminder', $notice->getKind());
        self::assertSame('Claustro', $notice->getTitle());
        self::assertSame('Hoy a las 10:00.', $notice->getBody());
        self::assertNotNull($event->getReminderSentAt());
    }

    public function testAReminderIsNeverPushedTwice(): void
    {
        $owner = $this->user('profe@centro.test');
        $this->event($owner, '2026-09-15 10:00', EventReminderOffset::TEN_MINUTES);
        $this->em->flush();

        $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 09:52'));
        // The sweep runs every few minutes, so the very next run sees the same still-future event.
        $second = $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 09:57'));

        self::assertSame(0, $second);
        self::assertCount(1, $this->notifications->findRecentFor($owner));
    }

    public function testNothingIsSentBeforeTheReminderIsDue(): void
    {
        $owner = $this->user('profe@centro.test');
        $this->event($owner, '2026-09-15 10:00', EventReminderOffset::TEN_MINUTES);
        $this->em->flush();

        self::assertSame(0, $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 09:30')));
    }

    public function testAnEventAlreadyUnderWayIsNotAnnounced(): void
    {
        // After an outage the sweep catches up; announcing "empieza en 10 minutos" about a meeting that
        // started half an hour ago would be worse than staying quiet.
        $owner = $this->user('profe@centro.test');
        $this->event($owner, '2026-09-15 10:00', EventReminderOffset::TEN_MINUTES);
        $this->em->flush();

        self::assertSame(0, $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 10:30')));
    }

    public function testAnEventWithoutAReminderIsNeverAnnounced(): void
    {
        $owner = $this->user('profe@centro.test');
        $this->event($owner, '2026-09-15 10:00', null);
        $this->em->flush();

        self::assertSame(0, $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 09:59')));
    }

    public function testEachOwnerOnlyGetsTheirOwnReminder(): void
    {
        $mine = $this->user('yo@centro.test');
        $theirs = $this->user('otra@centro.test');
        $this->event($mine, '2026-09-15 10:00', EventReminderOffset::TEN_MINUTES);
        $this->event($theirs, '2026-09-15 12:00', EventReminderOffset::TEN_MINUTES);
        $this->em->flush();

        $sent = $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 09:52'));

        self::assertSame(1, $sent);
        self::assertCount(1, $this->notifications->findRecentFor($mine));
        self::assertCount(0, $this->notifications->findRecentFor($theirs));
    }

    public function testADayAheadReminderReadsAsTomorrow(): void
    {
        $owner = $this->user('profe@centro.test');
        $this->event($owner, '2026-09-16 08:30', EventReminderOffset::ONE_DAY);
        $this->em->flush();

        $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 08:31'));

        $notice = $this->notifications->findRecentFor($owner)[0] ?? null;
        self::assertNotNull($notice);
        self::assertSame('Mañana a las 08:30.', $notice->getBody());
    }

    public function testAgendaRemindersDoNotGoOutByEmail(): void
    {
        $owner = $this->user('profe@centro.test');
        $this->event($owner, '2026-09-15 10:00', EventReminderOffset::TEN_MINUTES);
        $this->em->flush();

        $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 09:52'));

        self::assertEmailCount(0);
    }
}
