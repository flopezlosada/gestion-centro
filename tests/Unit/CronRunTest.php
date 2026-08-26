<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\CronRun;
use PHPUnit\Framework\TestCase;

/**
 * Los recortes de la fila del registro de ejecuciones.
 *
 * Parecen cosmética y no lo son: la fila que más importa escribir es la del FALLO, y un mensaje de
 * excepción kilométrico o la salida entera de un comando verboso reventarían el INSERT que está
 * intentando dejar constancia de ese fallo. El registro que se cae justo cuando hay algo que registrar
 * es peor que no tenerlo, porque además da la sensación de que no pasó nada.
 *
 * Los recortes viven en la entidad y no en quien escribe, aunque el registro se escriba por DBAL, para
 * que haya UNA sola regla.
 */
final class CronRunTest extends TestCase
{
    /**
     * Una salida larguísima se recorta quedándose con el FINAL, que es donde salen el resumen del
     * comando y la traza de un fallo, y se marca el recorte para que nadie lea media salida creyendo
     * que es entera.
     */
    public function testLaSalidaLargaSeRecortaPorElPrincipio(): void
    {
        // El carácter del principio no puede aparecer en la marca de recorte ("…(salida recortada)"),
        // o la comprobación se engañaría a sí misma.
        $run = (new CronRun())->setOutput(str_repeat('X', 100).str_repeat('b', CronRun::OUTPUT_MAX_LENGTH));

        $output = (string) $run->getOutput();

        self::assertStringContainsString('salida recortada', $output);
        self::assertStringEndsWith('b', $output);
        self::assertStringNotContainsString('X', $output, 'El principio es lo que se descarta.');
    }

    /**
     * Una salida que cabe se guarda tal cual, sin marca de recorte.
     */
    public function testLaSalidaCortaNoSeToca(): void
    {
        $run = (new CronRun())->setOutput("linea 1\nlinea 2");

        self::assertSame("linea 1\nlinea 2", $run->getOutput());
    }

    /**
     * Un resumen más largo que la columna se recorta a su largo exacto.
     */
    public function testElResumenSeRecortaAlLargoDeLaColumna(): void
    {
        $run = (new CronRun())->setDetail(str_repeat('x', 400));

        self::assertSame(255, mb_strlen((string) $run->getDetail()));
    }

    /**
     * El null sobrevive como null en los dos: «no hay resumen» no es lo mismo que «el resumen está
     * vacío», y un cast descuidado convertiría lo primero en lo segundo.
     */
    public function testElNullSigueSiendoNull(): void
    {
        $run = (new CronRun())->setDetail(null)->setOutput(null);

        self::assertNull($run->getDetail());
        self::assertNull($run->getOutput());
    }

    /**
     * Una fila recién abierta nace como FALLO y sin cierre: así, un proceso que muere a mitad (timeout
     * de php-fpm, kill, falta de memoria) deja constancia del fallo en lugar de desaparecer del
     * registro. «Arrancó y no volvió» tiene que poder distinguirse de «no arrancó».
     */
    public function testUnaEjecucionNaceComoFalloYSinCierre(): void
    {
        $run = new CronRun();

        self::assertSame(CronRun::STATUS_FAILED, $run->getStatus());
        self::assertFalse($run->isFinished());
        self::assertNull($run->getDurationSeconds(), 'Sin cierre no hay duración que medir.');
    }

    /**
     * Con cierre, la duración es la diferencia entre los dos sellos.
     */
    public function testLaDuracionSaleDeLosDosSellos(): void
    {
        $run = (new CronRun())
            ->setStartedAt(new \DateTimeImmutable('2027-03-04 07:00:00'))
            ->setFinishedAt(new \DateTimeImmutable('2027-03-04 07:00:42'));

        self::assertSame(42, $run->getDurationSeconds());
    }
}
