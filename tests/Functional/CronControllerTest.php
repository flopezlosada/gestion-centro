<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CronRun;
use App\Entity\PersonalEvent;
use App\Entity\User;
use App\Enum\EventReminderOffset;
use App\Repository\CronRunRepository;
use App\Service\Cron\Adapter\CentreCronManifest;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The HTTP cron endpoints are the only routes the app answers without a session, so the shared-secret
 * gate is their whole security: every route must reject a missing or wrong token, and none may need a
 * logged-in user to work.
 *
 * Estas dos son la vía ANTIGUA —un crontab del hosting llamando con `curl`—, no el planificador; de las
 * puertas de éste va {@see CronSchedulerEndpointsTest}. Siguen existiendo como plan B y desde que
 * llegó el planificador ejecutan las mismas tareas del manifiesto por el mismo runner, para que los dos
 * relojes puedan convivir sin pisarse: comparten gate, cerrojo y registro de ejecuciones. Eso es lo que
 * se comprueba aquí abajo, y no el texto exacto de la respuesta — el rastro canónico de lo que pasó es
 * ahora la fila de `cron_run`, no el cuerpo HTTP.
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
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(PersonalEvent::class)->find($id)?->getReminderSentAt());

        // El endpoint lleva los CUATRO barridos de la misma cadencia (agenda, RAICES, los recordatorios de
        // guardia de la tarde anterior y esa mañana, y reuniones), y ahora cada uno deja SU propia fila en
        // el registro con su propio resultado. Aquí no hay ninguna guardia en curso ni mañana, ni reunión a
        // punto de empezar, así que la de agenda es la única que declara trabajo hecho.
        $runs = self::getContainer()->get(CronRunRepository::class)->findLastRunPerTask();

        self::assertArrayHasKey(CentreCronManifest::CRON_EVENT_REMINDERS, $runs);
        self::assertSame(CronRun::STATUS_DONE, $runs[CentreCronManifest::CRON_EVENT_REMINDERS]->getStatus());
        self::assertStringContainsString('1 avisos de agenda enviados', (string) $runs[CentreCronManifest::CRON_EVENT_REMINDERS]->getDetail());

        foreach ([CentreCronManifest::CRON_GUARDIA_RAICES_REMINDERS, CentreCronManifest::CRON_GUARDIA_DUTY_REMINDERS, CentreCronManifest::CRON_MEETING_REMINDERS] as $sinTrabajo) {
            self::assertArrayHasKey($sinTrabajo, $runs, \sprintf('El barrido "%s" no se ha ejecutado.', $sinTrabajo));
            self::assertSame(
                CronRun::STATUS_NOTHING_TO_DO,
                $runs[$sinTrabajo]->getStatus(),
                \sprintf('El barrido "%s" no tenía trabajo y no debe registrarse como si lo hubiera hecho.', $sinTrabajo)
            );
        }
    }

    /**
     * La vía antigua se declara como el CRONTAB que es, no como el tick ni como una persona. Los tres
     * orígenes tienen que verse distintos en el registro: mientras los dos relojes convivan, es lo único
     * que permite saber cuál de ellos está vivo.
     */
    public function testLaViaAntiguaSeRegistraComoElCrontabDelHosting(): void
    {
        $this->client->request('GET', '/cron/task-reminders?token='.self::SECRET);

        self::assertResponseIsSuccessful();
        $runs = self::getContainer()->get(CronRunRepository::class)->findLastRunPerTask();

        self::assertArrayHasKey(CentreCronManifest::CRON_TASK_REMINDERS, $runs);
        self::assertSame(CronRun::TRIGGER_SCHEDULE, $runs[CentreCronManifest::CRON_TASK_REMINDERS]->getTriggerSource());
        self::assertArrayHasKey(
            CentreCronManifest::CRON_PURGE_LOG,
            $runs,
            'La poda del registro es diaria y viaja en este endpoint: si no, en esta vía crecería sin freno.'
        );
    }
}
