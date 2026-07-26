<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The inbox at /avisos. Each row must lead exactly where the same notice's push notification leads
 * (both read the destination from App\Support\NotificationLink), and a notice with nowhere better to
 * go must not link to the inbox the reader is already looking at.
 */
final class NotificationInboxTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function loggedInUser(): User
    {
        $user = (new User())->setFullName('Profe Test')->setEmail('profe@centro.test');
        $this->em->persist($user);

        return $user;
    }

    public function testAnAgendaReminderOpensAndForwardsToTheAgenda(): void
    {
        $user = $this->loggedInUser();
        $notification = new Notification($user, 'event.reminder', 'Claustro', 'Hoy a las 10:00.');
        $this->em->persist($notification);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/avisos');
        self::assertResponseIsSuccessful();
        // Every notice links to its open action (which marks it read on click); the destination — the
        // agenda for an agenda reminder — is resolved server-side by NotificationLink.
        self::assertSelectorExists('a.notice[href="/avisos/'.$notification->getId().'"]');
        self::assertSelectorTextContains('.notice__title', 'Claustro');

        $this->client->request('GET', '/avisos/'.$notification->getId());
        self::assertResponseRedirects('/agenda');
    }

    public function testANoticeWithNoBetterDestinationStillOpensAndReturnsToTheInbox(): void
    {
        $user = $this->loggedInUser();
        $notification = new Notification($user, 'system.notice', 'Aviso general', 'Sin destino.');
        $this->em->persist($notification);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/avisos');
        self::assertResponseIsSuccessful();
        // It is still a link to its open action (so opening it marks it read); it just returns here.
        self::assertSelectorExists('a.notice[href="/avisos/'.$notification->getId().'"]');

        $this->client->request('GET', '/avisos/'.$notification->getId());
        self::assertResponseRedirects('/avisos');
    }
}
