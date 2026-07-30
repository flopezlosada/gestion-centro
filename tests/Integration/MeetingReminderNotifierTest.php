<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Meeting;
use App\Entity\User;
use App\Enum\EventReminderOffset;
use App\Repository\NotificationRepository;
use App\Service\MeetingReminderNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The meeting reminder sweep: one notice per meeting fans out to everybody expected there (the convened
 * AND whoever convened it, who also has to turn up), exactly once, and never by e-mail — a "empieza en 10
 * minutos" is worth nothing in an inbox. Nobody outside the meeting hears about it.
 *
 * Every instant here is built without an explicit zone, i.e. in PHP's default one — the same the sweep and
 * Doctrine use (see {@see MeetingReminderNotifier}), so the test behaves the same in CI (UTC) and locally
 * (Madrid).
 */
final class MeetingReminderNotifierTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MeetingReminderNotifier $notifier;
    private NotificationRepository $notifications;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->notifier = self::getContainer()->get(MeetingReminderNotifier::class);
        $this->notifications = self::getContainer()->get(NotificationRepository::class);
    }

    private function user(string $email): User
    {
        $user = (new User())->setFullName($email)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    /**
     * @param list<User> $attendees the people convened
     */
    private function meeting(User $convener, array $attendees, string $start, ?EventReminderOffset $reminder, ?string $place = 'Sala de profesores'): Meeting
    {
        $meeting = new Meeting($convener, 'Reunión de departamento', new \DateTimeImmutable($start));
        $meeting->setPlace($place)->setReminder($reminder);
        foreach ($attendees as $attendee) {
            $meeting->addAttendee($attendee);
        }
        $this->em->persist($meeting);

        return $meeting;
    }

    public function testEverybodyExpectedIsNotifiedIncludingWhoeverConvened(): void
    {
        $convener = $this->user('coordina@centro.test');
        $attendee = $this->user('convocado@centro.test');
        $meeting = $this->meeting($convener, [$attendee], '2026-09-15 14:00', EventReminderOffset::TEN_MINUTES);
        $this->em->flush();

        $sent = $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 13:52'));

        // Dos avisos, una reunión: quien convoca también tiene que estar allí.
        self::assertSame(2, $sent);
        $notice = $this->notifications->findRecentFor($attendee)[0] ?? null;
        self::assertNotNull($notice);
        self::assertSame('meeting.reminder', $notice->getKind());
        self::assertSame('Reunión: Reunión de departamento', $notice->getTitle());
        self::assertSame('Hoy a las 14:00, en Sala de profesores.', $notice->getBody());
        self::assertCount(1, $this->notifications->findRecentFor($convener));
        self::assertNotNull($meeting->getReminderSentAt());
    }

    public function testAMeetingWithoutAPlaceStillReadsAsASentence(): void
    {
        $convener = $this->user('coordina@centro.test');
        $this->meeting($convener, [], '2026-09-15 14:00', EventReminderOffset::TEN_MINUTES, null);
        $this->em->flush();

        $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 13:52'));

        $notice = $this->notifications->findRecentFor($convener)[0] ?? null;
        self::assertNotNull($notice);
        self::assertSame('Hoy a las 14:00.', $notice->getBody());
    }

    public function testTheReminderIsNeverPushedTwice(): void
    {
        $convener = $this->user('coordina@centro.test');
        $attendee = $this->user('convocado@centro.test');
        $this->meeting($convener, [$attendee], '2026-09-15 14:00', EventReminderOffset::TEN_MINUTES);
        $this->em->flush();

        $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 13:52'));
        // El barrido corre cada pocos minutos: la siguiente pasada ve la misma reunión aún futura.
        $second = $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 13:57'));

        self::assertSame(0, $second);
        self::assertCount(1, $this->notifications->findRecentFor($attendee));
    }

    public function testNothingIsSentBeforeItIsDueOrOnceItHasStarted(): void
    {
        $convener = $this->user('coordina@centro.test');
        $this->meeting($convener, [], '2026-09-15 14:00', EventReminderOffset::TEN_MINUTES);
        $this->em->flush();

        self::assertSame(0, $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 13:30')), 'todavía no toca');
        // Tras una caída, el barrido se pone al día: anunciar "empieza en 10 minutos" de una reunión que
        // empezó hace media hora es peor que callarse.
        self::assertSame(0, $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 14:30')), 'ya ha empezado');
    }

    public function testAMeetingWithoutAReminderIsNeverAnnounced(): void
    {
        $convener = $this->user('coordina@centro.test');
        $this->meeting($convener, [$this->user('convocado@centro.test')], '2026-09-15 14:00', null);
        $this->em->flush();

        self::assertSame(0, $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 13:59')));
    }

    public function testNobodyOutsideTheMeetingHearsAboutIt(): void
    {
        $convener = $this->user('coordina@centro.test');
        $stranger = $this->user('ajena@centro.test');
        $this->meeting($convener, [], '2026-09-15 14:00', EventReminderOffset::TEN_MINUTES);
        $this->em->flush();

        $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 13:52'));

        self::assertCount(0, $this->notifications->findRecentFor($stranger));
    }

    public function testMeetingRemindersDoNotGoOutByEmail(): void
    {
        // La convocatoria SÍ va por correo; el "empieza en 10 minutos" no, que llega tarde por definición.
        $convener = $this->user('coordina@centro.test');
        $this->meeting($convener, [$this->user('convocado@centro.test')], '2026-09-15 14:00', EventReminderOffset::TEN_MINUTES);
        $this->em->flush();

        $this->notifier->sendDue(new \DateTimeImmutable('2026-09-15 13:52'));

        self::assertEmailCount(0);
    }
}
