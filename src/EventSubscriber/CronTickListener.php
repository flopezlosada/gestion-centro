<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Controller\CronTickController;
use App\Service\Cron\CronTick;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ejecuta el tick DESPUÉS de haber contestado al reloj externo.
 *
 * Symfony dispara `kernel.terminate` cuando la respuesta ya se ha enviado y
 * —bajo php-fpm— tras `fastcgi_finish_request()`, así que aquí se trabaja con la
 * conexión cerrada. Eso importa por dos razones distintas:
 *
 * 1. Un envío de correos por SMTP puede tardar más que el tiempo máximo que
 *    php-fpm concede a una petición, y moriría a mitad.
 * 2. Los relojes externos que avisan cuando algo falla —que son los que nos
 *    interesan, porque avisar es el criterio por el que se eligen— consideran
 *    fallida una llamada que tarda demasiado. Contestar al instante evita que
 *    nos avisen de una avería que no existe.
 *
 * Sólo actúa si la petición pasó por {@see CronTickController} y trajo el token
 * bueno: el atributo lo pone el controlador después de comprobarlo.
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
class CronTickListener
{
    public function __construct(
        private readonly CronTick $tick,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param TerminateEvent $event Evento de fin de petición.
     */
    public function __invoke(TerminateEvent $event): void
    {
        if (!$event->getRequest()->attributes->get(CronTickController::TICK_ATTRIBUTE, false)) {
            return;
        }

        // Sin límite de tiempo: ya no hay nadie esperando al otro lado, y el
        // cerrojo de cada tarea impide que un tick lento se solape con el
        // siguiente.
        @set_time_limit(0);

        $done = $this->tick->run();

        // Deja constancia de la pasada aunque no hubiera nada que hacer: es la
        // única traza de que el reloj externo está vivo, porque las pasadas sin
        // trabajo no escriben en el registro de ejecuciones.
        $this->logger->info('Tick del planificador: {count} tarea(s) ejecutada(s). {detail}', [
            'count' => count($done),
            'detail' => $done === [] ? 'Nada que hacer.' : json_encode($done, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
