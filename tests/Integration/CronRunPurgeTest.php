<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\PurgeCronLogCommand;
use App\Entity\CronRun;
use App\Repository\CronRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La poda del registro de ejecuciones.
 *
 * Existe porque este proyecto tiene cinco tareas a cadencia de minutos: unas 1.700 filas al día, más de
 * medio millón al año en un hosting compartido. El registro es un log técnico, no un histórico.
 *
 * Y tiene una invariante que NO es una optimización, es la razón de ser del registro: la última
 * ejecución de cada tarea NO se borra nunca, por vieja que sea. El chequeo de salud mide el retraso
 * contra ella y, sin ninguna ejecución registrada, no ve retraso. Una poda ciega borraría la última
 * fila de una tarea muerta hace meses y devolvería `/cron/health` a verde — el silencio otra vez, que es
 * exactamente la avería que todo esto cierra. Ese es el caso que más se cuida aquí.
 */
final class CronRunPurgeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CronRunRepository $runs;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->runs = self::getContainer()->get(CronRunRepository::class);
    }

    /**
     * Lo viejo se va y lo reciente se queda.
     */
    public function testSeVaLoViejoYSeQuedaLoReciente(): void
    {
        $this->record('cron.demo', '-60 days');
        $this->record('cron.demo', '-40 days');
        $this->record('cron.demo', '-2 days');
        $reciente = $this->record('cron.demo', '-1 hour');

        $deleted = $this->runs->purgeOlderThan(new \DateTimeImmutable(\sprintf('-%d days', PurgeCronLogCommand::RETENTION_DAYS)));

        self::assertSame(2, $deleted);
        $quedan = $this->runs->findRecentForTask('cron.demo', 20);
        self::assertCount(2, $quedan);
        self::assertSame($reciente->getId(), $quedan[0]->getId());
    }

    /**
     * EL CASO QUE IMPORTA: una tarea muerta hace meses conserva su última ejecución, aunque esté muy por
     * fuera de la ventana. Si se borrara, el chequeo de salud volvería a verde y la caída se haría
     * invisible por segunda vez — ahora por culpa de la propia limpieza.
     */
    public function testLaUltimaEjecucionDeUnaTareaMuertaNoSeBorraNunca(): void
    {
        $ultima = $this->record('cron.tarea_muerta', '-200 days');
        $this->record('cron.tarea_muerta', '-300 days');

        $this->runs->purgeOlderThan(new \DateTimeImmutable(\sprintf('-%d days', PurgeCronLogCommand::RETENTION_DAYS)));

        $quedan = $this->runs->findRecentForTask('cron.tarea_muerta', 20);
        self::assertCount(1, $quedan, 'Se conserva exactamente una: la última.');
        self::assertSame($ultima->getId(), $quedan[0]->getId());
    }

    /**
     * Y lo conserva PARA CADA tarea, no solo para la última que se mirase: con seis tareas, quedarse con
     * un único superviviente global cegaría el chequeo de las otras cinco.
     */
    public function testConservaLaUltimaDeCadaTareaPorSeparado(): void
    {
        foreach (['cron.uno', 'cron.dos', 'cron.tres'] as $task) {
            $this->record($task, '-100 days');
            $this->record($task, '-90 days');
        }

        $this->runs->purgeOlderThan(new \DateTimeImmutable('-30 days'));

        foreach (['cron.uno', 'cron.dos', 'cron.tres'] as $task) {
            self::assertCount(1, $this->runs->findRecentForTask($task, 20), \sprintf('La tarea "%s" se ha quedado sin referencia.', $task));
        }
    }

    /**
     * Con el registro vacío no hay nada que borrar y no revienta: es el estado del primer día, y el de
     * todos los días una vez la poda va al corriente.
     */
    public function testConElRegistroVacioNoHaceNada(): void
    {
        self::assertSame(0, $this->runs->purgeOlderThan(new \DateTimeImmutable('-30 days')));
    }

    /**
     * Una fila del registro con la fecha de arranque que se le diga.
     *
     * @param string $taskKey clave de la tarea
     * @param string $when    desplazamiento relativo, p. ej. "-40 days"
     *
     * @return CronRun la fila ya persistida
     */
    private function record(string $taskKey, string $when): CronRun
    {
        $run = (new CronRun())
            ->setTaskKey($taskKey)
            ->setCommand('app:demo')
            ->setStatus(CronRun::STATUS_NOTHING_TO_DO)
            ->setTriggerSource(CronRun::TRIGGER_TICK)
            ->setStartedAt(new \DateTimeImmutable($when))
            ->setFinishedAt(new \DateTimeImmutable($when));

        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }
}
