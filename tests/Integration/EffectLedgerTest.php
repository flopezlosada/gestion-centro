<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\EmittedEffect;
use App\Service\Cron\EffectLedger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El guardián de idempotencia, contra MariaDB de verdad.
 *
 * Va con base de datos y no con dobles a propósito: lo que se está probando ES el índice único. Un
 * doble de la conexión probaría que el código llama a insert(), no que dos apuntes con la misma clave
 * sean imposibles, que es la única garantía que aquí importa — y la que un `if` en PHP no puede dar,
 * porque dos procesos pueden comprobar a la vez que un aviso falta y mandarlo los dos.
 *
 * Ninguna tarea del centro lo usa todavía (los cinco barridos ya llevan su propio sello en su propia
 * tabla). Se prueba igual, y por eso: la primera tarea que lo estrene no tiene por qué descubrir aquí
 * un fallo, y para entonces mandará correo de verdad a personas de verdad.
 */
final class EffectLedgerTest extends KernelTestCase
{
    private const string KIND = 'test_effect';

    /**
     * La primera vez el efecto se produce y queda apuntado con su clave y su destino.
     */
    public function testLaPrimeraVezProduceElEfectoYLoApunta(): void
    {
        self::bootKernel();
        $veces = 0;

        $emitted = $this->ledger()->once(
            self::KIND,
            'user-1',
            new \DateTimeImmutable('2027-01-15'),
            static function () use (&$veces): void { ++$veces; },
            'profe@centro.test',
        );

        self::assertTrue($emitted);
        self::assertSame(1, $veces);

        $apunte = $this->findEffect('user-1', '2027-01-15');
        self::assertNotNull($apunte, 'El efecto producido debe quedar apuntado.');
        self::assertSame('profe@centro.test', $apunte->getTarget());
    }

    /**
     * El caso que da sentido a todo esto: repetir la tarea no repite el efecto. Con dos relojes
     * llamando al mismo tick, esto es lo que evita el aviso duplicado.
     */
    public function testLaSegundaVezNoLoProduce(): void
    {
        self::bootKernel();
        $veces = 0;
        $efecto = static function () use (&$veces): void { ++$veces; };
        $ledger = $this->ledger();

        $primera = $ledger->once(self::KIND, 'user-2', new \DateTimeImmutable('2027-01-15'), $efecto);
        $segunda = $ledger->once(self::KIND, 'user-2', new \DateTimeImmutable('2027-01-15'), $efecto);

        self::assertTrue($primera);
        self::assertFalse($segunda, 'La segunda llamada con la misma clave no debe producir el efecto.');
        self::assertSame(1, $veces, 'El efecto se ha producido una sola vez.');
    }

    /**
     * La FECHA forma parte de la clave: el aviso de la reunión de esta semana no bloquea el de la
     * siguiente. Sin esto, un recordatorio se mandaría una sola vez en la vida a cada persona.
     */
    public function testOtraFechaEsOtroEfecto(): void
    {
        self::bootKernel();
        $veces = 0;
        $efecto = static function () use (&$veces): void { ++$veces; };
        $ledger = $this->ledger();

        $ledger->once(self::KIND, 'user-3', new \DateTimeImmutable('2027-01-15'), $efecto);
        $ledger->once(self::KIND, 'user-3', new \DateTimeImmutable('2027-01-22'), $efecto);

        self::assertSame(2, $veces, 'Dos días distintos son dos efectos distintos.');
    }

    /**
     * Y la REFERENCIA también: dos personas del mismo día son dos avisos. Es la granularidad correcta —
     * un sello por tarea y día dejaría sin aviso a todo el mundo menos al primero.
     */
    public function testOtraReferenciaEsOtroEfecto(): void
    {
        self::bootKernel();
        $veces = 0;
        $efecto = static function () use (&$veces): void { ++$veces; };
        $ledger = $this->ledger();

        $ledger->once(self::KIND, 'user-4', new \DateTimeImmutable('2027-01-15'), $efecto);
        $ledger->once(self::KIND, 'user-5', new \DateTimeImmutable('2027-01-15'), $efecto);

        self::assertSame(2, $veces);
    }

    /**
     * Si el efecto FALLA, el apunte se retira y el siguiente intento lo recoge. Es lo que hace que un
     * SMTP caído a mitad del envío no deje a nadie sin aviso para siempre.
     */
    public function testUnEfectoQueFallaNoQuedaApuntadoYSeReintenta(): void
    {
        self::bootKernel();
        $ledger = $this->ledger();
        $fecha = new \DateTimeImmutable('2027-01-15');

        $thrown = null;
        try {
            $ledger->once(self::KIND, 'user-6', $fecha, static function (): void {
                throw new \RuntimeException('SMTP caído de prueba');
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $thrown, 'El fallo debe seguir su curso: el guardián no se lo traga.');
        self::assertNull($this->findEffect('user-6', '2027-01-15'), 'Un efecto que no llegó a producirse no debe quedar apuntado.');

        $reintento = $ledger->once(self::KIND, 'user-6', $fecha, static function (): void {});
        self::assertTrue($reintento, 'El reintento debe poder producir el efecto que falló.');
    }

    /**
     * El reenvío explícito SÍ repite el efecto, aunque ya constara. No es el planificador reintentando
     * por su cuenta: es alguien que sabe que ese aviso no llegó y pide repetirlo. Sin esa vía, rescatar
     * un aviso perdido exigiría borrar el apunte a mano en la base de datos.
     */
    public function testElReenvioExplicitoRepiteElEfecto(): void
    {
        self::bootKernel();
        $veces = 0;
        $efecto = static function () use (&$veces): void { ++$veces; };
        $ledger = $this->ledger();
        $fecha = new \DateTimeImmutable('2027-01-15');

        $ledger->once(self::KIND, 'user-8', $fecha, $efecto);
        $reenvio = $ledger->once(self::KIND, 'user-8', $fecha, $efecto, resend: true);

        self::assertTrue($reenvio);
        self::assertSame(2, $veces, 'Una orden humana de reenviar sí repite.');
    }

    /**
     * Un destino más largo que la columna no puede reventar el INSERT y con él el envío: se recorta al
     * persistir.
     */
    public function testUnDestinoLarguisimoNoRevientaElApunte(): void
    {
        self::bootKernel();

        $emitted = $this->ledger()->once(
            self::KIND,
            'user-7',
            new \DateTimeImmutable('2027-01-15'),
            static function (): void {},
            str_repeat('a', 400).'@centro.test',
        );

        self::assertTrue($emitted);
        self::assertSame(255, mb_strlen((string) $this->findEffect('user-7', '2027-01-15')?->getTarget()));
    }

    /**
     * Servicio real del contenedor de test.
     *
     * @return EffectLedger el guardián
     */
    private function ledger(): EffectLedger
    {
        return self::getContainer()->get(EffectLedger::class);
    }

    /**
     * Apunte guardado para una clave, o null si no hay.
     *
     * @param string $reference  referencia del efecto
     * @param string $occurredOn fecha de negocio en formato YYYY-MM-DD
     *
     * @return EmittedEffect|null el apunte
     */
    private function findEffect(string $reference, string $occurredOn): ?EmittedEffect
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        // El guardián escribe por DBAL, así que el EntityManager puede tener en su identity map una
        // versión anterior (o la ausencia) de estas filas.
        $em->clear();

        return $em->getRepository(EmittedEffect::class)->findOneBy([
            'kind' => self::KIND,
            'reference' => $reference,
            'occurredOn' => new \DateTimeImmutable($occurredOn),
        ]);
    }
}
