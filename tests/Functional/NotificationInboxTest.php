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

    public function testAnAgendaReminderLinksToTheAgenda(): void
    {
        $user = $this->loggedInUser();
        $this->em->persist(new Notification($user, 'event.reminder', 'Claustro', 'Hoy a las 10:00.'));
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/avisos');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.notice[href="/agenda"]');
        self::assertSelectorTextContains('.notice__title', 'Claustro');
    }

    public function testANoticeWithNoBetterDestinationIsNotLinked(): void
    {
        $user = $this->loggedInUser();
        $this->em->persist(new Notification($user, 'system.notice', 'Aviso general', 'Sin destino.'));
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/avisos');

        self::assertResponseIsSuccessful();
        // Rendered as a plain block, not as a link back to this very page.
        self::assertSelectorExists('div.notice');
        self::assertSelectorNotExists('a.notice');
    }
}
