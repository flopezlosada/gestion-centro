<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CronRunRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Poda el registro de ejecuciones de tareas programadas.
 *
 * POR QUÉ HACE FALTA, y por qué en este proyecto y no en el de origen: cinco de las seis tareas van a
 * cadencia de minutos, no de días. Con el tick pasando cada cinco minutos, los cuatro barridos de
 * agenda, guardias y reuniones escriben una fila cada uno por pasada — unas 1.700 al día, más de medio
 * millón al año. El registro es un log técnico, no un histórico: lo que da valor es la última ejecución
 * de cada tarea y las últimas semanas para poder mirar atrás cuando algo va mal.
 *
 * La ventana de conservación va en código y no en un ajuste ({@see self::RETENTION_DAYS}): nadie del
 * centro tiene por qué decidir esto, y el único efecto de tocarla es cuánto ocupa una tabla que nadie
 * mira.
 *
 * NO PODA LA ÚLTIMA EJECUCIÓN DE CADA TAREA, por muy vieja que sea, y no es un detalle de eficiencia:
 * el chequeo de salud mide el retraso contra ella, y sin ninguna ejecución registrada NO ve retraso.
 * Una poda ciega borraría la última fila de una tarea muerta hace meses y devolvería `/cron/health` a
 * verde — el silencio otra vez ({@see CronRunRepository::purgeOlderThan()}).
 *
 * `emitted_effect` NO se poda todavía, a propósito: hoy ninguna tarea apunta efectos ahí, así que
 * podarla sería código muerto. Quien estrene {@see \App\Service\Cron\EffectLedger} tendrá que añadir
 * su poda aquí, y con una ventana MÁS LARGA que la ventana de negocio más larga que mire una tarea —
 * borrar un apunte que una tarea todavía puede volver a mirar es reabrir la puerta al efecto duplicado
 * que ese apunte existía para cerrar.
 */
#[AsCommand(name: 'app:cron:purge-log', description: 'Retira del registro de tareas programadas las ejecuciones más viejas que la ventana de conservación')]
final class PurgeCronLogCommand extends AbstractCronCommand
{
    /**
     * Cuántos días de historial se conservan.
     *
     * 30 con la cadencia de hoy son unas 50.000 filas: bastante para reconstruir qué pasó el mes
     * pasado, poco para que la tabla estorbe en un hosting compartido.
     */
    public const int RETENTION_DAYS = 30;

    public function __construct(private readonly CronRunRepository $runs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Ejecuta aunque la tarea esté pausada en los ajustes');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $cutoff = new \DateTimeImmutable(\sprintf('-%d days', self::RETENTION_DAYS));
        $deleted = $this->runs->purgeOlderThan($cutoff);

        $detail = \sprintf(
            '%d ejecuciones retiradas del registro (anteriores al %s; se conserva siempre la última de cada tarea).',
            $deleted,
            $cutoff->format('d/m/Y'),
        );

        (new SymfonyStyle($input, $output))->success($detail);

        return $deleted > 0 ? $this->didWork($detail) : $this->nothingToDo('Nada que retirar del registro.');
    }
}
