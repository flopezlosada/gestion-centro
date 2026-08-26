<?php

declare(strict_types=1);

namespace App\Service\Cron;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Salida que escribe en su destino real Y se queda una copia en memoria.
 *
 * Sirve para que {@see \App\Command\AbstractCronCommand} pueda guardar en el
 * registro de ejecuciones lo que el comando imprimió, sin cambiar en nada lo
 * que se ve por pantalla. Importa sobre todo para el cron del hosting: sin
 * SSH, la única forma de leer `var/log/cron.log` es bajarlo por FTP, y con la
 * copia en base de datos la salida se puede consultar sin salir de la aplicación.
 *
 * La copia se guarda SIN códigos de color, aunque el destino real sí los
 * lleve, para que el texto persistido sea legible tal cual.
 *
 * Nota: a propósito NO implementa ConsoleOutputInterface. Eso hace que
 * SymfonyStyle mande también los errores por este único canal en lugar de a
 * stderr, que es justo lo que se quiere aquí (capturarlo todo); en el cron da
 * igual, porque el crontab redirige stderr al mismo log.
 */
final class TeeOutput extends Output
{
    /** Copia de todo lo escrito, ya sin códigos de color. */
    private string $captured = '';

    /**
     * @param OutputInterface $inner Salida real a la que se sigue escribiendo.
     */
    public function __construct(
        private readonly OutputInterface $inner,
    ) {
        parent::__construct($inner->getVerbosity(), $inner->isDecorated(), $inner->getFormatter());
    }

    /**
     * Todo lo que el comando ha escrito hasta ahora, sin códigos de color.
     */
    public function getCaptured(): string
    {
        return $this->captured;
    }

    /**
     * Escribe en el destino real el mensaje TAL CUAL (ya viene formateado por
     * esta misma clase, así que se pasa en modo RAW para no formatearlo dos
     * veces) y acumula una copia limpia.
     *
     * @param string $message Mensaje ya formateado.
     * @param bool   $newline ¿Añadir salto de línea?
     */
    protected function doWrite(string $message, bool $newline): void
    {
        $this->inner->write($message, $newline, OutputInterface::OUTPUT_RAW);

        $this->captured .= Helper::removeDecoration($this->getFormatter(), $message)
            . ($newline ? \PHP_EOL : '');
    }
}
