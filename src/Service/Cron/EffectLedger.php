<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Entity\EmittedEffect;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guardián de idempotencia: garantiza que un efecto con una clave dada se
 * produce UNA sola vez, por muchas veces que se pida.
 *
 * No sabe qué es el efecto. Recibe una clave y algo que hacer, y decide si toca
 * hacerlo. Sirve igual para mandar un correo, emitir un cobro o subir un
 * fichero; el que llama pone el significado ({@see EmittedEffect} explica la
 * forma de la clave).
 *
 * EL PROTOCOLO, en tres tiempos:
 *
 * 1. Se APUNTA el efecto antes de producirlo. El apunte es un INSERT contra un
 *    índice único: si otro proceso ya lo apuntó, el INSERT choca y aquí se sabe
 *    que no hay nada que hacer. La exclusión la impone el motor, no un `if`
 *    previo — dos procesos pueden comprobar a la vez que algo falta.
 * 2. Se PRODUCE el efecto.
 * 3. Si producirlo falla, se BORRA el apunte para que el siguiente intento lo
 *    recoja. Un fallo de envío no debe dejar a nadie marcado como avisado.
 *
 * LÍMITE CONOCIDO, asumido a propósito: si el proceso muere de golpe entre el
 * apunte y el efecto (php-fpm mata por tiempo, falta de memoria), ese efecto
 * concreto no se produce y no se reintenta. Es la elección clásica entre perder
 * un efecto rarísimo o duplicarlos, y aquí duplicar es peor: significa cobrar
 * dos veces o escribir dos veces a la misma persona. Cubrir esa ventana exigiría
 * estados intermedios y una política de reintentos, mucha complejidad para
 * milisegundos.
 *
 * ATENCIÓN SI ALGÚN DÍA SE INTRODUCE ENVÍO ASÍNCRONO. Hoy el correo sale en
 * síncrono (no hay Messenger en el proyecto), así que un fallo de SMTP llega
 * aquí como excepción y el paso 3 funciona. Con una cola por medio, `send()`
 * volvería sin error aunque el envío fracasara después, y el apunte se quedaría
 * puesto: habría que mover el borrado al fallo del consumidor de la cola.
 *
 * Escribe por DBAL y no por el EntityManager, por lo mismo que
 * {@see CronRunLogger}: un `flush()` arrastraría la unidad de trabajo del
 * comando, y si una excepción de Doctrine cierra el EntityManager, el apunte
 * tiene que poder borrarse igual.
 */
class EffectLedger
{
    private const TABLE = 'emitted_effect';

    /** El apunte se ha escrito: nadie más producirá este efecto. */
    private const CLAIM_TAKEN = 'taken';

    /** El apunte ya estaba: el efecto se produjo antes. */
    private const CLAIM_ALREADY_EMITTED = 'already_emitted';

    /**
     * No se ha podido apuntar por un problema de infraestructura. Se produce el
     * efecto igual (sin protección) porque no entregar es peor que arriesgar un
     * duplicado, que además exige dos procesos concurrentes.
     */
    private const CLAIM_UNPROTECTED = 'unprotected';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Produce el efecto sólo si no consta ya emitido para esa clave.
     *
     * @param string            $kind       Clase de efecto ("meeting_reminder"…).
     * @param string            $reference  A qué o quién se refiere ("partner-76").
     * @param \DateTimeInterface $occurredOn Fecha de negocio del efecto.
     * @param callable          $effect     Lo que hay que hacer una sola vez.
     * @param string|null       $target     Destino, para poder auditarlo después.
     * @param bool              $resend     Orden humana explícita de repetirlo.
     * @return bool true si el efecto se ha producido ahora; false si ya constaba.
     */
    public function once(
        string $kind,
        string $reference,
        \DateTimeInterface $occurredOn,
        callable $effect,
        ?string $target = null,
        bool $resend = false,
    ): bool {
        $claim = $this->claim($kind, $reference, $occurredOn, $target);

        // El reenvío sigue adelante aunque el efecto ya constara: no es el
        // planificador reintentando por su cuenta, es alguien que sabe que ese
        // correo no llegó y pide repetirlo. El apunte viejo se queda como está
        // (sigue siendo verdad que el efecto se produjo aquel día).
        if ($claim === self::CLAIM_ALREADY_EMITTED && !$resend) {
            return false;
        }

        try {
            $effect();
        } catch (\Throwable $e) {
            if ($claim === self::CLAIM_TAKEN) {
                $this->discard($kind, $reference, $occurredOn);
            }

            throw $e;
        }

        return true;
    }

    /**
     * Apunta el efecto antes de producirlo. Devuelve una de las tres constantes
     * CLAIM_*.
     *
     * @param string             $kind       Clase de efecto.
     * @param string             $reference  Referencia del efecto.
     * @param \DateTimeInterface $occurredOn Fecha de negocio.
     * @param string|null        $target     Destino, si lo hay.
     */
    private function claim(string $kind, string $reference, \DateTimeInterface $occurredOn, ?string $target): string
    {
        // El recorte del destino vive en la entidad (una sola regla), así que se
        // reutiliza aunque aquí se escriba por DBAL.
        $shaped = (new EmittedEffect())->setTarget($target);

        try {
            $this->connection()->insert(self::TABLE, [
                'kind' => $kind,
                'reference' => $reference,
                'occurred_on' => $occurredOn->format('Y-m-d'),
                'target' => $shaped->getTarget(),
                'emitted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            return self::CLAIM_TAKEN;
        } catch (UniqueConstraintViolationException) {
            return self::CLAIM_ALREADY_EMITTED;
        } catch (\Throwable $e) {
            $this->logger->warning('No se pudo apuntar el efecto {kind}/{reference}, se produce sin protección: {error}', [
                'kind' => $kind,
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return self::CLAIM_UNPROTECTED;
        }
    }

    /**
     * Retira el apunte de un efecto que no llegó a producirse, para que el
     * siguiente intento lo recoja.
     *
     * @param string             $kind       Clase de efecto.
     * @param string             $reference  Referencia del efecto.
     * @param \DateTimeInterface $occurredOn Fecha de negocio.
     */
    private function discard(string $kind, string $reference, \DateTimeInterface $occurredOn): void
    {
        try {
            $this->connection()->delete(self::TABLE, [
                'kind' => $kind,
                'reference' => $reference,
                'occurred_on' => $occurredOn->format('Y-m-d'),
            ]);
        } catch (\Throwable $e) {
            // Queda un apunte de un efecto que no ocurrió: esa clave no se
            // reintentará. Es lo peor que puede pasar aquí y hay que poder
            // verlo en el log.
            $this->logger->error('El efecto {kind}/{reference} falló y su apunte no se pudo retirar; no se reintentará: {error}', [
                'kind' => $kind,
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Conexión DBAL. Se pide al EntityManager en cada llamada porque, si una
     * excepción lo ha cerrado, la conexión sigue siendo válida aunque él no.
     */
    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
