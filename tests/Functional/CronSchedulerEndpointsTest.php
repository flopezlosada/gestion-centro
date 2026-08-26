<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CronRun;
use App\Repository\CronRunRepository;
use App\Service\AppSettings;
use App\Service\Cron\Adapter\CentreCronManifest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Las dos puertas del planificador: el tick que llama el reloj y el chequeo de salud que vigila un
 * monitor externo.
 *
 * Son de naturaleza opuesta a propósito y conviene no «armonizarlas»:
 *
 * - `/cron/tick` EJECUTA, así que va cerrado con token en cabecera y responde 404 —no 401 ni 403— a todo
 *   lo que no lo traiga: quien no tenga el token no llega ni a saber que aquí hay algo.
 * - `/cron/health` solo LEE, así que va abierto: no ejecuta nada, no recibe parámetros y no expone datos
 *   personales. Pedir token obligaría a configurarlo en el monitor, y un chequeo que cuesta configurar
 *   acaba sin configurarse — que es la avería original.
 *
 * Los tests arrancan con TODAS las tareas apagadas: lo que se prueba aquí es la puerta, no el trabajo (de
 * eso va CronTickTest), y así ninguna pasada intenta avisar a nadie. Los que necesitan que el tick
 * ejecute ALGO —los que comprueban qué reloj queda registrado— encienden solo la poda del registro, que
 * es la única tarea inocua: no avisa a nadie y borra filas viejas que en la base de test no existen.
 */
final class CronSchedulerEndpointsTest extends WebTestCase
{
    /** El mismo valor que .env.test; no es un secreto real. */
    private const string TOKEN = 'test-cron-tick-token';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $settings = self::getContainer()->get(AppSettings::class);
        foreach (array_keys(CentreCronManifest::TASKS) as $task) {
            $settings->setCronTaskEnabled($task, false);
        }
    }

    /**
     * Sin cabecera, el endpoint no existe. 404 y no 401: un 401 confirmaría que ahí hay algo que
     * proteger.
     */
    public function testSinTokenElTickNoExiste(): void
    {
        $this->client->request('POST', '/cron/tick');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Con un token equivocado, exactamente lo mismo: la respuesta no distingue «no hay endpoint» de
     * «token mal», así que no se puede sondear.
     */
    public function testConTokenFalsoElTickTampocoExiste(): void
    {
        $this->client->request('POST', '/cron/tick', server: ['HTTP_X_CRON_TOKEN' => 'no-es-el-bueno']);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * El token va en CABECERA y no en la query string. En la query quedaría escrito en los logs de
     * acceso del servidor y en cualquier proxy por medio, así que pasarlo por ahí tiene que seguir
     * siendo un 404.
     */
    public function testElTokenEnLaQueryStringNoVale(): void
    {
        $this->client->request('POST', '/cron/tick?token='.self::TOKEN);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Con el token bueno, 202 y sin cuerpo: el endpoint acepta y contesta ANTES de trabajar. El trabajo
     * se hace en `kernel.terminate`, con la conexión ya cerrada, por dos razones — que un envío lento no
     * se coma el tiempo máximo de php-fpm, y que el reloj externo no dé por fallida una llamada que
     * tarda y avise de una avería que no existe.
     */
    public function testConElTokenBuenoAceptaElTick(): void
    {
        $this->client->request('POST', '/cron/tick', server: ['HTTP_X_CRON_TOKEN' => self::TOKEN]);

        self::assertResponseStatusCodeSame(202);
        self::assertSame('', $this->client->getResponse()->getContent());
    }

    /**
     * Acepta GET además de POST: no todos los relojes gratuitos saben mandar POST, y exigirlo dejaría
     * fuera justo a los servicios que avisan cuando fallan, que es el criterio por el que se eligen.
     */
    public function testAceptaTambienGet(): void
    {
        $this->client->request('GET', '/cron/tick', server: ['HTTP_X_CRON_TOKEN' => self::TOKEN]);

        self::assertResponseStatusCodeSame(202);
    }

    /**
     * Un tick aceptado con todas las tareas apagadas no ejecuta nada y —esto es lo que importa— no
     * escribe ni una fila «apagada» en el registro. Con el tick pasando cada cinco minutos, hacerlo
     * llenaría la tabla de ruido y el registro dejaría de decir nada.
     */
    public function testUnTickConTodoApagadoNoEnsuciaElRegistro(): void
    {
        $this->client->request('POST', '/cron/tick', server: ['HTTP_X_CRON_TOKEN' => self::TOKEN]);

        self::assertResponseStatusCodeSame(202);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(0, self::getContainer()->get(CronRunRepository::class)->count([]));
    }

    /**
     * CADA RELOJ SE IDENTIFICA, y el registro los distingue.
     *
     * Es la diferencia que delata el fallo más probable de todo el montaje: hay dos relojes, el principal
     * cada cinco minutos y el de respaldo cada hora. Si el principal muere y el respaldo sigue vivo, las
     * tareas SIGUEN corriendo —tarde— y desde fuera todo parece normal, con los avisos de «10 minutos
     * antes» llegando cincuenta minutos después de servir para algo. Registrar los dos como «el reloj»
     * devolvería ese caso a la invisibilidad de la que venimos.
     *
     * El respaldo se declara con la cabecera; el principal es un servicio externo configurado en una web
     * ajena, así que va por omisión — la observabilidad no puede depender de que alguien acierte una
     * cabecera en un formulario de terceros.
     */
    public function testCadaRelojSeIdentificaEnElRegistro(): void
    {
        $settings = self::getContainer()->get(AppSettings::class);
        $settings->setCronTaskEnabled(CentreCronManifest::CRON_PURGE_LOG, true);

        $this->client->request('POST', '/cron/tick', server: [
            'HTTP_X_CRON_TOKEN' => self::TOKEN,
            'HTTP_X_CRON_CLOCK' => 'backup',
        ]);

        self::assertResponseStatusCodeSame(202);
        self::assertSame(CronRun::TRIGGER_TICK_BACKUP, $this->lastRun()?->getTriggerSource());
    }

    /**
     * Sin cabecera, el que llama es el reloj PRINCIPAL. Es el valor por defecto a propósito.
     */
    public function testSinCabeceraElRelojEsElPrincipal(): void
    {
        self::getContainer()->get(AppSettings::class)->setCronTaskEnabled(CentreCronManifest::CRON_PURGE_LOG, true);

        $this->client->request('POST', '/cron/tick', server: ['HTTP_X_CRON_TOKEN' => self::TOKEN]);

        self::assertSame(CronRun::TRIGGER_TICK, $this->lastRun()?->getTriggerSource());
    }

    /**
     * Una cabecera inventada NO llega al registro: se traduce a una constante en el controlador, así que
     * lo que se guarda es siempre uno de los orígenes declarados. El origen es un dato que se pinta en
     * una pantalla, y no tiene por qué ser texto de nadie de fuera.
     */
    public function testUnaCabeceraInventadaNoLlegaAlRegistro(): void
    {
        self::getContainer()->get(AppSettings::class)->setCronTaskEnabled(CentreCronManifest::CRON_PURGE_LOG, true);

        $this->client->request('POST', '/cron/tick', server: [
            'HTTP_X_CRON_TOKEN' => self::TOKEN,
            'HTTP_X_CRON_CLOCK' => "<b>me lo invento</b>' --",
        ]);

        self::assertSame(CronRun::TRIGGER_TICK, $this->lastRun()?->getTriggerSource());
    }

    /**
     * El chequeo de salud responde SIN token y en 200 cuando no hay nada fuera de plazo, publicando la
     * cadencia de cada tarea encendida en castellano.
     */
    public function testLaSaludRespondeSinTokenYEnVerde(): void
    {
        $settings = self::getContainer()->get(AppSettings::class);
        $settings->setCronTaskEnabled(CentreCronManifest::CRON_TASK_REMINDERS, true);

        $this->client->request('GET', '/cron/health');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($payload['ok']);
        self::assertSame([], $payload['late']);
        self::assertSame('a diario a las 07:00', $payload['tasks'][CentreCronManifest::CRON_TASK_REMINDERS]['schedule']);
    }

    /**
     * Y responde 503 con la tarea en la lista `late` en cuanto una ENCENDIDA se pasa de su plazo. Es el
     * código el que dispara el aviso: el monitor externo no lee el JSON, mira el 503.
     */
    public function testLaSaludDa503ConUnaTareaFueraDePlazo(): void
    {
        $settings = self::getContainer()->get(AppSettings::class);
        $settings->setCronTaskEnabled(CentreCronManifest::CRON_TASK_REMINDERS, true);
        $this->recordStaleRun(CentreCronManifest::CRON_TASK_REMINDERS);

        $this->client->request('GET', '/cron/health');

        self::assertResponseStatusCodeSame(503);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($payload['ok']);
        self::assertSame([CentreCronManifest::CRON_TASK_REMINDERS], $payload['late']);
    }

    /**
     * Una tarea APAGADA fuera de plazo no cuenta como caída: está apagada. Tratarla como caída llenaría
     * el monitor de falsas alarmas hasta que nadie le hiciera caso — que es exactamente cómo se pierden
     * tres semanas sin enterarse.
     */
    public function testUnaTareaApagadaFueraDePlazoNoEsUnaCaida(): void
    {
        $this->recordStaleRun(CentreCronManifest::CRON_TASK_REMINDERS);

        $this->client->request('GET', '/cron/health');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([], $payload['late']);
        self::assertArrayNotHasKey(CentreCronManifest::CRON_TASK_REMINDERS, $payload['tasks'], 'Una tarea apagada no se evalúa.');
    }

    /**
     * La última ejecución registrada de cualquier tarea, o null si no hay ninguna.
     *
     * El repositorio se pide DESPUÉS de la petición: el navegador de test reinicia el kernel entre
     * peticiones, y además el registro se escribe por DBAL desde el otro kernel.
     *
     * @return CronRun|null la ejecución más reciente
     */
    private function lastRun(): ?CronRun
    {
        return self::getContainer()->get(CronRunRepository::class)
            ->findRecentForTask(CentreCronManifest::CRON_PURGE_LOG, 1)[0] ?? null;
    }

    /**
     * Una ejecución registrada muy por fuera del plazo de su tarea.
     *
     * @param string $taskKey clave de la tarea
     */
    private function recordStaleRun(string $taskKey): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(
            (new CronRun())
                ->setTaskKey($taskKey)
                ->setCommand('app:demo')
                ->setStatus(CronRun::STATUS_DONE)
                ->setTriggerSource(CronRun::TRIGGER_TICK)
                ->setStartedAt(new \DateTimeImmutable('-10 days'))
                ->setFinishedAt(new \DateTimeImmutable('-10 days'))
        );
        $em->flush();
    }
}
