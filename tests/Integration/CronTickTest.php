<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\CronRun;
use App\Repository\CronRunRepository;
use App\Service\AppSettings;
use App\Service\Cron\Adapter\CentreCronManifest;
use App\Service\Cron\CronTick;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El latido: qué elige ejecutar en cada pasada, y qué queda escrito.
 *
 * Se ejercita con TODAS las tareas apagadas menos la poda del registro, que es la única inocua (no
 * avisa a nadie y solo borra filas viejas que en la base de test no existen). Así el tick corre de
 * verdad, de punta a punta —gate, cerrojo, comando, registro— sin mandar un correo a nadie.
 *
 * No hace falta limpiar nada al terminar: la suite corre con dama/doctrine-test-bundle, que envuelve
 * cada test en una transacción y la deshace.
 */
final class CronTickTest extends KernelTestCase
{
    /**
     * Una tarea encendida que nunca ha corrido se ejecuta en el primer tick, y queda registrada como
     * disparada por EL TICK — ni a mano, ni por el crontab del hosting.
     *
     * Los tres orígenes tienen que verse distintos, y el que importa es el tercero: mientras el crontab
     * viejo y el tick nuevo convivan, el viejo dispara en punto, llega antes y deja al tick sin nada que
     * hacer. Si ambos se registraran igual, «el tick funciona» y «el tick está muerto» serían
     * indistinguibles en el registro, que es justo la ceguera que se quiere cerrar.
     */
    public function testEjecutaLoQueTocaYLoRegistraComoDelTick(): void
    {
        self::bootKernel();
        $this->onlyEnabled(CentreCronManifest::CRON_PURGE_LOG);

        $done = self::getContainer()->get(CronTick::class)->run();

        self::assertArrayHasKey(CentreCronManifest::CRON_PURGE_LOG, $done);

        $run = $this->lastRun(CentreCronManifest::CRON_PURGE_LOG);
        self::assertNotNull($run, 'La ejecución del tick debe quedar registrada.');
        self::assertSame(CronRun::TRIGGER_TICK, $run->getTriggerSource());
        self::assertNotSame(
            CronRun::TRIGGER_SCHEDULE,
            $run->getTriggerSource(),
            'Confundir el tick con el crontab del hosting deja el traspaso a ciegas.'
        );
    }

    /**
     * Una tarea que corre y no encuentra trabajo se registra como «nada que hacer», NO como «hizo su
     * trabajo».
     *
     * Es la distinción de la que depende todo lo demás. Con cuatro barridos pasando cada cinco minutos,
     * la respuesta honesta a casi todas las pasadas es «no tocaba»; si todas salieran `done`, el registro
     * no podría distinguir una tarea que avisa de una que solo se ejecuta, y una avería de entrega
     * pasaría por normalidad.
     */
    public function testUnaPasadaSinTrabajoNoSeRegistraComoTrabajoHecho(): void
    {
        self::bootKernel();
        $this->onlyEnabled(CentreCronManifest::CRON_PURGE_LOG);

        self::getContainer()->get(CronTick::class)->run();

        // La base de test no tiene registro viejo que podar, así que la poda no encuentra nada.
        self::assertSame(CronRun::STATUS_NOTHING_TO_DO, $this->lastRun(CentreCronManifest::CRON_PURGE_LOG)?->getStatus());
    }

    /**
     * El segundo tick de la misma ocurrencia no repite nada. Es lo que hace que un reloj llamando cada
     * cinco minutos —o DOS relojes en paralelo, que es el diseño— no disparen la tarea diaria una y otra
     * vez.
     */
    public function testUnSegundoTickNoRepiteElTrabajo(): void
    {
        self::bootKernel();
        $this->onlyEnabled(CentreCronManifest::CRON_PURGE_LOG);
        $tick = self::getContainer()->get(CronTick::class);

        $tick->run();
        $segundo = $tick->run();

        self::assertSame([], $segundo, 'Ya corrió en esta ocurrencia: no toca otra vez.');
        self::assertCount(1, $this->runsOf(CentreCronManifest::CRON_PURGE_LOG), 'Ni una fila de más en el registro.');
    }

    /**
     * Las tareas apagadas ni se miran. Con el tick pasando cada cinco minutos, dejarlas llegar al gate
     * del comando llenaría el registro de filas «apagada» —288 al día por tarea— y el registro dejaría
     * de decir nada útil.
     */
    public function testNoTocaLasTareasApagadas(): void
    {
        self::bootKernel();
        $this->onlyEnabled(null);

        self::assertSame([], self::getContainer()->get(CronTick::class)->run());
        self::assertNull($this->lastRun(CentreCronManifest::CRON_PURGE_LOG), 'Ni siquiera debe registrarse.');
    }

    /**
     * Una tarea que se ha pasado de su plazo se recupera SOLA en el siguiente tick, sin que nadie la
     * relance. Es la prueba que justifica el diseño entero: un reloj caído deja de ser un aviso
     * perdido para siempre y pasa a ser un aviso que llega tarde.
     */
    public function testUnaTareaFueraDePlazoSeRecuperaEnElSiguienteTick(): void
    {
        self::bootKernel();
        $this->onlyEnabled(CentreCronManifest::CRON_PURGE_LOG);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tick = self::getContainer()->get(CronTick::class);

        $tick->run();
        $run = $this->lastRun(CentreCronManifest::CRON_PURGE_LOG);
        self::assertNotNull($run);

        // Se retrasa a mano la única ejecución más allá del plazo de la tarea (36 h): es simular que el
        // reloj estuvo muerto dos días.
        $run->setStartedAt(new \DateTimeImmutable('-40 hours'));
        $em->flush();

        self::assertArrayHasKey(
            CentreCronManifest::CRON_PURGE_LOG,
            $tick->run(),
            'Con la ocurrencia de hoy sin cubrir, la tarea tiene que volver a correr sola.'
        );
    }

    /**
     * Deja encendida SOLO la tarea indicada (o ninguna, con null).
     *
     * @param string|null $key clave de la tarea a dejar encendida
     */
    private function onlyEnabled(?string $key): void
    {
        $settings = self::getContainer()->get(AppSettings::class);
        foreach (array_keys(CentreCronManifest::TASKS) as $task) {
            $settings->setCronTaskEnabled($task, $task === $key);
        }
    }

    /**
     * Última ejecución registrada de una tarea, o null si no hay.
     *
     * @param string $taskKey clave de la tarea
     *
     * @return CronRun|null la ejecución más reciente
     */
    private function lastRun(string $taskKey): ?CronRun
    {
        return $this->runsOf($taskKey)[0] ?? null;
    }

    /**
     * Historial registrado de una tarea, de más nueva a más vieja.
     *
     * @param string $taskKey clave de la tarea
     *
     * @return CronRun[] las ejecuciones
     */
    private function runsOf(string $taskKey): array
    {
        // El registro se escribe por DBAL, así que el EntityManager puede tener en su identity map la
        // ausencia de estas filas.
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(CronRunRepository::class)->findRecentForTask($taskKey, 20);
    }
}
