<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PersonalEvent;
use App\Entity\User;
use App\Enum\EventReminderOffset;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The HTTP cron endpoints are the only routes the app answers without a session, so the shared-secret
 * gate is their whole security: every route must reject a missing or wrong token, and none may need a
 * logged-in user to work.
 */
final class CronControllerTest extends WebTestCase
{
    /** Matches CRON_SECRET in .env.test. */
    private const string SECRET = 'test-cron-secret';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return iterable<string, array{string}> the cron paths, keyed by a readable name
     */
    public static function cronPaths(): iterable
    {
        yield 'task reminders' => ['/cron/task-reminders'];
        yield 'event reminders' => ['/cron/event-reminders'];
    }

    #[DataProvider('cronPaths')]
    public function testACronEndpointRejectsAMissingToken(string $path): void
    {
        $this->client->request('GET', $path);

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('cronPaths')]
    public function testACronEndpointRejectsAWrongToken(string $path): void
    {
        $this->client->request('GET', $path.'?token=nope');

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('cronPaths')]
    public function testACronEndpointRunsWithTheRightTokenAndNoSession(string $path): void
    {
        $this->client->request('GET', $path.'?token='.self::SECRET);

        self::assertResponseIsSuccessful();
    }

    public function testTheEventCronPushesADueAgendaReminder(): void
    {
        $owner = (new User())->setFullName('Profe Test')->setEmail('profe@centro.test');
        $this->em->persist($owner);
        // Due in five minutes with a ten-minute reminder: the sweep must pick it up right now.
        $event = new PersonalEvent($owner, 'Claustro', new \DateTimeImmutable('+5 minutes'));
        $event->setReminder(EventReminderOffset::TEN_MINUTES);
        $this->em->persist($event);
        $this->em->flush();
        $id = (int) $event->getId();

        $this->client->request('GET', '/cron/event-reminders?token='.self::SECRET);

        self::assertResponseIsSuccessful();
        // El endpoint lleva los TRES barridos de la misma cadencia (agenda, RAICES y reuniones), así que
        // informa de los tres. Aquí no hay ninguna guardia en curso ni reunión a punto de empezar, de ahí
        // los ceros.
        self::assertStringContainsString('1 avisos de agenda, 0 de RAICES y 0 de reuniones enviados.', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(PersonalEvent::class)->find($id)?->getReminderSentAt());
    }
}
