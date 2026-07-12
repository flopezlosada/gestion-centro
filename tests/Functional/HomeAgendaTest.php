<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PersonalEvent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The landing agenda mixes the user's tasks and their private personal events — but only their own:
 * a personal event is never shown on someone else's home page.
 */
final class HomeAgendaTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    public function testOwnPersonalEventShowsOnTheHomeAgenda(): void
    {
        $user = $this->user('profe@centro.test');
        // A couple of days out so it lands in the "next 7 days" bucket regardless of wall-clock.
        $event = new PersonalEvent($user, 'Tutoría con familia', new \DateTimeImmutable('+2 days'));
        $this->em->persist($event);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Tutoría con familia', (string) $this->client->getResponse()->getContent());
    }

    public function testAnotherUsersPersonalEventDoesNotShowOnMyHomeAgenda(): void
    {
        $owner = $this->user('duena@centro.test');
        $me = $this->user('yo@centro.test');
        $event = new PersonalEvent($owner, 'Cita privada ajena', new \DateTimeImmutable('+2 days'));
        $this->em->persist($event);
        $this->em->flush();

        $this->client->loginUser($me);
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Cita privada ajena', (string) $this->client->getResponse()->getContent());
    }
}
