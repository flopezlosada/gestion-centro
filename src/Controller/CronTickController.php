<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CronRun;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * El endpoint que llama el reloj externo: "es la hora".
 *
 * NO recibe qué ejecutar. Un endpoint que aceptara el nombre de la tarea sería
 * una consola remota publicada en internet; éste sólo dice que ha pasado el
 * tiempo, y qué hacer con eso lo decide la aplicación cruzando su manifiesto con
 * el registro de ejecuciones ({@see \App\Service\Cron\CronTick}).
 *
 * DEFENSAS, por orden:
 *
 * - **Sin token configurado, no existe.** Si `CRON_TICK_TOKEN` está vacío
 *   responde 404 igual que si la ruta no estuviera declarada. Así el endpoint no
 *   puede quedarse abierto por descuido en un despliegue: hay que encenderlo a
 *   propósito.
 * - **Token en cabecera, no en la URL.** En la query string quedaría escrito en
 *   los logs de acceso del servidor y en cualquier proxy por medio.
 * - **`hash_equals`**, para no filtrar el token por el tiempo de respuesta.
 * - **404 opaco siempre.** Ni 401 ni 403: quien no traiga el token correcto no
 *   llega a saber que aquí hay algo.
 *
 * Peor caso con el token robado: ticks de más. No duplican nada, porque las
 * tareas van dirigidas por estado, los efectos externos son idempotentes y el
 * cerrojo impide que se solapen. No lee ni borra datos.
 *
 * EL TRABAJO NO SE HACE AQUÍ. Se responde 202 al instante y las tareas se
 * ejecutan en `kernel.terminate` ({@see \App\EventSubscriber\CronTickListener}),
 * con la conexión ya cerrada. Si no, un envío de correos lento se comería el
 * tiempo máximo de php-fpm y moriría a mitad — y además el reloj externo daría
 * el tick por fallido y avisaría de una avería que no existe.
 */
class CronTickController
{
    /** Cabecera en la que el reloj externo manda el token. */
    public const TOKEN_HEADER = 'X-Cron-Token';

    /** Atributo con el que el controlador le dice al listener que hay que trabajar. */
    public const TICK_ATTRIBUTE = '_cron_tick';

    /**
     * Cabecera con la que un reloj se identifica: "main" el principal, "backup" el de respaldo.
     *
     * NO es un dato de seguridad y no hace falta que lo sea: quien no traiga el token no llega hasta
     * aquí, y lo peor que puede hacer un reloj legítimo mintiendo sobre su nombre es ensuciar una
     * etiqueta del registro. Lo que sí importa es que **quien no se identifique no herede la identidad
     * de nadie**: un valor ausente o desconocido queda como «sin identificar» y no como el principal.
     */
    public const CLOCK_HEADER = 'X-Cron-Clock';

    /** Valor de {@see self::CLOCK_HEADER} con el que el reloj PRINCIPAL (cada 5 min) se declara. */
    public const CLOCK_MAIN = 'main';

    /** Valor de {@see self::CLOCK_HEADER} con el que el reloj de respaldo se declara. */
    public const CLOCK_BACKUP = 'backup';

    /** Atributo donde viaja al listener el origen que se registrará (uno de los CronRun::TRIGGER_*). */
    public const TRIGGER_ATTRIBUTE = '_cron_tick_trigger';

    /**
     * No extiende el controlador base del proyecto: no necesita nada de él (ni
     * plantillas, ni sesión, ni Doctrine), y así el endpoint más expuesto de la
     * aplicación tiene la superficie más pequeña posible.
     *
     * @param string $cronTickToken Token esperado; vacío = endpoint apagado.
     */
    public function __construct(
        #[Autowire('%env(CRON_TICK_TOKEN)%')]
        private readonly string $cronTickToken,
    ) {
    }

    /**
     * Acepta GET y POST porque no todos los relojes gratuitos saben mandar POST,
     * y exigirlo dejaría fuera justo a los servicios que avisan cuando fallan,
     * que es el criterio por el que se eligen.
     *
     * @param Request $request Petición del reloj externo.
     */
    #[Route('/cron/tick', name: 'cron_tick', methods: ['GET', 'POST'])]
    public function tick(Request $request): Response
    {
        $this->denyUnlessTokenMatches($request);

        // El trabajo lo hará el listener de kernel.terminate; aquí sólo se deja
        // la señal y se contesta.
        $request->attributes->set(self::TICK_ATTRIBUTE, true);

        // Y QUIÉN llama, para que el registro pueda distinguir los relojes. Se traduce a una constante
        // aquí mismo en vez de pasar el valor de la cabecera adelante: así lo que se guarda es siempre
        // uno de los orígenes declarados, no lo que a alguien le apetezca mandar.
        //
        // El `default` NO cae en el principal, y ésa es la lección: si cayera, cualquier llamada sin
        // cabecera —un curl de prueba, un reloj nuevo mal configurado— se haría pasar por él y el
        // registro daría por vivo un reloj que igual no existe.
        $request->attributes->set(self::TRIGGER_ATTRIBUTE, match ($request->headers->get(self::CLOCK_HEADER)) {
            self::CLOCK_MAIN => CronRun::TRIGGER_TICK_MAIN,
            self::CLOCK_BACKUP => CronRun::TRIGGER_TICK_BACKUP,
            default => CronRun::TRIGGER_TICK,
        });

        return new Response('', Response::HTTP_ACCEPTED);
    }

    /**
     * Comprueba el token o hace desaparecer el endpoint.
     *
     * @param Request $request Petición entrante.
     * @throws NotFoundHttpException Siempre que el token no cuadre.
     */
    private function denyUnlessTokenMatches(Request $request): void
    {
        $expected = trim($this->cronTickToken);
        $given = (string) $request->headers->get(self::TOKEN_HEADER, '');

        // Sin token configurado el endpoint no existe. La comprobación va
        // primero para no llegar a comparar contra una cadena vacía, que
        // cualquiera acertaría.
        if ($expected === '' || !hash_equals($expected, $given)) {
            throw new NotFoundHttpException();
        }
    }
}
