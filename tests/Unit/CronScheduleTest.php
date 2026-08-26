<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\CronRun;
use App\Service\Cron\CronManifest;
use App\Service\Cron\CronSchedule;
use PHPUnit\Framework\TestCase;

/**
 * El evaluador de cadencias: la pieza que decide, en cada tick, a qué tareas les toca correr.
 *
 * Es la regla más delicada de todo el planificador, porque un error aquí no da un fallo visible: da una
 * tarea que no corre y nadie echa de menos hasta que alguien se queda sin su aviso. Por eso los casos
 * que más se cuidan son los de recuperación —el tick que se perdió— y los de frontera con la hora
 * declarada.
 *
 * LA REGLA no es «¿son las siete de la mañana?». Esa pregunta obliga a que el reloj sea puntual: si el
 * tick de las 07:00 se pierde, la tarea no corre ese día. La pregunta es «¿ha corrido desde la última
 * vez que le tocaba?», y con eso un tick perdido lo recupera el siguiente y un tick repetido no hace
 * nada.
 *
 * Sin kernel y sin base de datos a propósito: esta clase solo pide al manifiesto su zona horaria.
 */
final class CronScheduleTest extends TestCase
{
    /** La cadencia real de los cuatro barridos de minutos de este centro. */
    private const array EVERY_FIVE_MINUTES = ['freq' => 'interval', 'minutes' => 5];

    /** La cadencia real del barrido diario de avisos de tareas. */
    private const array DAILY = ['freq' => 'daily', 'hour' => 7];

    /**
     * Una tarea que nunca ha corrido siempre toca: no hay constancia de que se hiciera, y los barridos
     * son idempotentes por estado (si no hay trabajo, salen con «nada que hacer»).
     */
    public function testSinNingunaEjecucionSiempreToca(): void
    {
        self::assertTrue($this->schedule()->isDue(self::DAILY, null, $this->moment('2027-03-04 09:30')));
        self::assertTrue($this->schedule()->isDue(self::EVERY_FIVE_MINUTES, null, $this->moment('2027-03-04 09:30')));
    }

    /**
     * LA CADENCIA QUE DOMINA ESTE PROYECTO: cada cinco minutos, medidos desde la última ejecución y no
     * contra el reloj de pared. Con la medida contra el reloj, un tick que llegara a las 10:04 en vez de
     * las 10:00 se saltaría la pasada entera.
     */
    public function testElIntervaloSeMideDesdeLaUltimaEjecucion(): void
    {
        $haceCuatroMinutos = $this->execution('2027-03-04 09:56');
        $schedule = $this->schedule();

        self::assertFalse($schedule->isDue(self::EVERY_FIVE_MINUTES, $haceCuatroMinutos, $this->moment('2027-03-04 10:00')));
        self::assertTrue($schedule->isDue(self::EVERY_FIVE_MINUTES, $haceCuatroMinutos, $this->moment('2027-03-04 10:01')));
    }

    /**
     * Y el respaldo horario no rompe nada: cuando el reloj de cinco minutos se muere y solo queda el de
     * GitHub pasando cada hora, la tarea de cinco minutos sigue tocando en cada pasada. Corre tarde,
     * pero corre — que es exactamente lo que se le pide a una red de seguridad.
     */
    public function testConSoloElRelojHorarioLaTareaDeMinutosSigueCorriendo(): void
    {
        $haceUnaHora = $this->execution('2027-03-04 09:00');

        self::assertTrue($this->schedule()->isDue(self::EVERY_FIVE_MINUTES, $haceUnaHora, $this->moment('2027-03-04 10:00')));
    }

    /**
     * Lo normal de la diaria: corrió ayer, hoy ya ha pasado su hora, le toca.
     */
    public function testLaDiariaTocaCuandoPasaLaHoraYNoHaCorridoHoy(): void
    {
        $ayer = $this->execution('2027-03-03 07:00');
        $schedule = $this->schedule();

        self::assertTrue($schedule->isDue(self::DAILY, $ayer, $this->moment('2027-03-04 07:00')));
        self::assertTrue($schedule->isDue(self::DAILY, $ayer, $this->moment('2027-03-04 23:59')));
    }

    /**
     * Ya corrió hoy después de su hora: no se repite por muchos ticks que pasen. Es lo que hace que un
     * reloj de cinco minutos no dispare el barrido diario 288 veces al día.
     */
    public function testLaDiariaNoSeRepiteSiYaCorrioHoy(): void
    {
        $hoy = $this->execution('2027-03-04 07:02');
        $schedule = $this->schedule();

        self::assertFalse($schedule->isDue(self::DAILY, $hoy, $this->moment('2027-03-04 08:00')));
        self::assertFalse($schedule->isDue(self::DAILY, $hoy, $this->moment('2027-03-04 23:00')));
    }

    /**
     * Antes de su hora no toca, aunque hoy no haya corrido todavía: la ejecución anterior cubre la
     * ocurrencia de ayer.
     */
    public function testLaDiariaNoTocaAntesDeSuHora(): void
    {
        $ayer = $this->execution('2027-03-03 07:01');

        self::assertFalse($this->schedule()->isDue(self::DAILY, $ayer, $this->moment('2027-03-04 06:59')));
    }

    /**
     * EL CASO QUE JUSTIFICA LA REGLA. El tick de las 07:00 no llegó (servidor caído, reloj dormido, lo
     * que sea). A media mañana la tarea SIGUE tocando, porque no ha corrido desde la última vez que le
     * tocaba. Con la regla ingenua —«¿son las siete?»— ese día se habría perdido para siempre, que es
     * exactamente lo que pasó en agosto de 2026.
     */
    public function testLaDiariaSeRecuperaCuandoElTickSePierde(): void
    {
        $anteayer = $this->execution('2027-03-02 07:01');
        $schedule = $this->schedule();

        self::assertTrue($schedule->isDue(self::DAILY, $anteayer, $this->moment('2027-03-03 11:00')), 'El mismo día debe recuperarse.');
        self::assertTrue($schedule->isDue(self::DAILY, $anteayer, $this->moment('2027-03-05 20:00')), 'Y dos días después también.');
    }

    /**
     * La semanal, con la misma regla de recuperación: el lunes que nadie la ejecutó sigue debiéndose el
     * martes. No la usa ninguna tarea del centro todavía, y se prueba igual porque la primera que se
     * añada no tiene por qué descubrir un fallo aquí.
     */
    public function testLaSemanalSeRecuperaYNoSeRepiteDentroDeLaSemana(): void
    {
        $weekly = ['freq' => 'weekly', 'dow' => 1, 'hour' => 6];
        $schedule = $this->schedule();

        // Lunes 1 de marzo de 2027 a las 06:00 era su momento; nadie la ejecutó.
        self::assertTrue($schedule->isDue($weekly, $this->execution('2027-02-22 06:01'), $this->moment('2027-03-02 11:00')));
        // Y si sí corrió ese lunes, no se repite en toda la semana.
        self::assertFalse($schedule->isDue($weekly, $this->execution('2027-03-01 06:03'), $this->moment('2027-03-06 23:00')));
    }

    /**
     * La mensual, ídem: el día declarado del mes y la misma recuperación.
     */
    public function testLaMensualTocaTrasSuDiaYNoAntes(): void
    {
        $monthly = ['freq' => 'monthly', 'dom' => 1, 'hour' => 4];
        $elMesPasado = $this->execution('2027-02-01 04:00');
        $schedule = $this->schedule();

        self::assertFalse($schedule->isDue($monthly, $elMesPasado, $this->moment('2027-02-28 23:00')));
        self::assertTrue($schedule->isDue($monthly, $elMesPasado, $this->moment('2027-03-01 04:00')));
        self::assertTrue($schedule->isDue($monthly, $elMesPasado, $this->moment('2027-03-15 12:00')), 'A mitad de mes sigue debiéndose.');
    }

    /**
     * Un intento FALLIDO se reintenta en el siguiente tick: un SMTP caído cinco minutos no puede dejar a
     * nadie sin aviso hasta mañana. Es seguro porque cada barrido es idempotente por estado y porque el
     * cerrojo impide que dos intentos se pisen.
     */
    public function testUnFalloSeReintentaEnElSiguienteTick(): void
    {
        $fallo = $this->execution('2027-03-04 07:00', CronRun::STATUS_FAILED);
        $schedule = $this->schedule();

        self::assertTrue($schedule->isDue(self::DAILY, $fallo, $this->moment('2027-03-04 07:30')));
        self::assertTrue($schedule->isDue(self::DAILY, $fallo, $this->moment('2027-03-04 23:00')), 'El reintento no caduca dentro de su ocurrencia.');
    }

    /**
     * Los otros tres resultados NO se reintentan dentro de su ocurrencia: «corrió sin encontrar trabajo»
     * es un éxito y «apagada por configuración» es la tarea obedeciendo.
     */
    public function testNoSeReintentaLoQueNoFallo(): void
    {
        $schedule = $this->schedule();
        $ahora = $this->moment('2027-03-04 12:00');

        self::assertFalse($schedule->isDue(self::DAILY, $this->execution('2027-03-04 07:00', CronRun::STATUS_DISABLED), $ahora));
        self::assertFalse($schedule->isDue(self::DAILY, $this->execution('2027-03-04 07:00', CronRun::STATUS_NOTHING_TO_DO), $ahora));
        self::assertFalse($schedule->isDue(self::DAILY, $this->execution('2027-03-04 07:00', CronRun::STATUS_DONE), $ahora));
    }

    /**
     * Las horas se entienden en la zona que declara el MANIFIESTO, no en la del servidor. Mismo instante
     * exacto, dos zonas, dos respuestas: en la Península ya son las 07:30 y la tarea de las 07:00 toca;
     * en Canarias son las 06:30 y todavía no le ha llegado la hora.
     *
     * Importa aquí y no es teoría: CI corre en UTC y el centro en Madrid.
     */
    public function testLaHoraSeEntiendeEnLaZonaDelManifiesto(): void
    {
        $instante = new \DateTimeImmutable('2027-03-04 06:30', new \DateTimeZone('UTC'));
        $anoche = $this->execution('2027-03-03 20:00');

        self::assertTrue($this->schedule('Europe/Madrid')->isDue(self::DAILY, $anoche, $instante));
        self::assertFalse($this->schedule('Atlantic/Canary')->isDue(self::DAILY, $anoche, $instante));
    }

    /**
     * Las cadencias se dicen en castellano, que es lo que se pinta junto a cada tarea en `/cron/health`.
     */
    public function testLasCadenciasSeDicenEnCastellano(): void
    {
        $schedule = $this->schedule();

        self::assertSame('a diario a las 07:00', $schedule->describe(self::DAILY));
        self::assertSame('cada 5 minutos', $schedule->describe(self::EVERY_FIVE_MINUTES));
        self::assertSame('los lunes a las 06:00', $schedule->describe(['freq' => 'weekly', 'dow' => 1, 'hour' => 6]));
        self::assertSame('el día 1 de cada mes a las 04:00', $schedule->describe(['freq' => 'monthly', 'dom' => 1, 'hour' => 4]));
        self::assertSame('cada hora', $schedule->describe(['freq' => 'interval', 'minutes' => 60]));
        self::assertSame('cada 2 horas', $schedule->describe(['freq' => 'interval', 'minutes' => 120]));
    }

    /**
     * Evaluador con un manifiesto que solo aporta la zona horaria (es lo único que esta pieza le pide).
     *
     * @param string $timezone zona horaria declarada
     *
     * @return CronSchedule el evaluador listo para preguntarle
     */
    private function schedule(string $timezone = 'Europe/Madrid'): CronSchedule
    {
        $manifest = $this->createMock(CronManifest::class);
        $manifest->method('timezone')->willReturn($timezone);

        return new CronSchedule($manifest);
    }

    /**
     * Una ejecución registrada en un instante dado.
     *
     * @param string $when   momento "Y-m-d H:i" en hora peninsular
     * @param string $status estado con el que quedó registrada
     *
     * @return CronRun la ejecución
     */
    private function execution(string $when, string $status = CronRun::STATUS_DONE): CronRun
    {
        return (new CronRun())
            ->setStartedAt($this->moment($when))
            ->setStatus($status);
    }

    /**
     * Un instante en hora peninsular, que es en la que están escritos los casos.
     *
     * (No se llama `at()`: PHPUnit ya tiene un `TestCase::at()` estático y redefinirlo es un error
     * fatal que tumba la suite entera antes de ejecutar nada.)
     *
     * @param string $when momento "Y-m-d H:i"
     *
     * @return \DateTimeImmutable el instante en la zona del centro
     */
    private function moment(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when, new \DateTimeZone('Europe/Madrid'));
    }
}
