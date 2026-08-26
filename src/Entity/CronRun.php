<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CronRunRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Registro de UNA ejecución de una tarea programada.
 *
 * Existe porque hasta ahora nada en el sistema vigilaba que las tareas corrieran: el cron del hosting
 * dejó de ejecutarse el 2 de agosto de 2026 y estuvo 22 días sin mandar un solo aviso sin que nada lo
 * delatara — desde fuera, «no había nada que avisar» y «no se ejecutó» se ven exactamente igual. Con
 * una fila por ejecución, el chequeo de salud puede decir por cada tarea cuándo corrió por última vez
 * y qué pasó.
 *
 * Se escribe desde {@see \App\Command\AbstractCronCommand}, así que registra IGUAL las ejecuciones
 * del cron por consola y las del tick: si solo registrara una vía, una caída de la otra seguiría
 * siendo invisible.
 *
 * Cuatro estados, no dos ({@see self::STATUS_DISABLED} … {@see self::STATUS_FAILED}): «apagada por
 * configuración» y «corrió sin encontrar trabajo» son situaciones sanas pero distintas, y ninguna de
 * las dos es «hizo su trabajo». Sin esa distinción, el chequeo de salud o llena de falsas alarmas u
 * oculta caídas reales.
 *
 * Sin claves ajenas y sin entidad relacionada a propósito: es un log técnico, tiene que poder
 * escribirse cuando el resto del modelo está en un estado inconsistente y purgarse por antigüedad sin
 * arrastrar nada ({@see \App\Command\PurgeCronLogCommand}).
 */
#[ORM\Entity(repositoryClass: CronRunRepository::class)]
#[ORM\Table(name: 'cron_run')]
// Sirve a las dos únicas consultas: la última ejecución de cada tarea y el historial de una.
#[ORM\Index(name: 'IDX_cron_run_task_started', columns: ['task_key', 'started_at'])]
// Sirve a la purga por antigüedad, que barre por fecha sin mirar la tarea.
#[ORM\Index(name: 'IDX_cron_run_started', columns: ['started_at'])]
class CronRun
{
    /** Apagada por configuración: no llegó a ejecutarse. No es un fallo. */
    public const string STATUS_DISABLED = 'disabled';

    /** Se ejecutó y no había trabajo que hacer. Es un resultado sano. */
    public const string STATUS_NOTHING_TO_DO = 'nothing_to_do';

    /** Se ejecutó e hizo trabajo. */
    public const string STATUS_DONE = 'done';

    /**
     * Falló. También es el estado con el que nace toda ejecución: así, un proceso que muere sin
     * cerrar su fila (timeout, kill, OOM) queda como fallo con `finished_at` a NULL, en vez de
     * desaparecer del registro.
     */
    public const string STATUS_FAILED = 'failed';

    /**
     * Lanzada por el crontab del hosting, que ejecuta `bin/console` directamente. Es el valor por
     * defecto porque ese camino no pasa por ninguna pieza nuestra que pueda declararse: llega, corre
     * y no avisa de nada.
     */
    public const string TRIGGER_SCHEDULE = 'schedule';

    /**
     * Lanzada por el tick, o sea por un reloj externo llamando a `/cron/tick`.
     *
     * Existe separada de {@see self::TRIGGER_SCHEDULE} por una razón operativa muy concreta: durante
     * el traspaso los DOS relojes pueden convivir, y sin distinguirlos no hay manera de saber si el
     * nuevo funciona — el viejo dispara en punto, llega antes y deja al tick sin trabajo que hacer,
     * así que «no pasa nada» y «el tick está muerto» se ven exactamente igual.
     */
    public const string TRIGGER_TICK = 'tick';

    /** Lanzada a mano por alguien (un botón, o `bin/console --force` a conciencia). */
    public const string TRIGGER_MANUAL = 'manual';

    /**
     * Tope de caracteres de salida que se persisten. La columna es TEXT, pero guardar la salida
     * entera de un comando verboso no aporta nada y engorda la tabla sin límite.
     */
    public const int OUTPUT_MAX_LENGTH = 8000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Clave de la tarea en el manifiesto ({@see \App\Service\Cron\Adapter\CentreCronManifest}), p. ej.
     * "cron.meeting_reminders".
     */
    #[ORM\Column(name: 'task_key', length: 100)]
    private string $taskKey = '';

    /**
     * Nombre del comando de consola ejecutado, p. ej. "app:meetings:send-reminders". Redundante con
     * la clave, pero se guarda para que el registro siga siendo legible si el manifiesto cambia.
     */
    #[ORM\Column(length: 120)]
    private string $command = '';

    /** Uno de los cuatro estados: disabled | nothing_to_do | done | failed. */
    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_FAILED;

    /**
     * Origen del disparo: schedule (el crontab) | tick (el reloj externo) | manual (una persona).
     * Sin este dato el registro mentiría — «corrió esta mañana» cuando en realidad alguien lo lanzó a
     * mano porque el reloj estaba caído.
     */
    #[ORM\Column(name: 'trigger_source', length: 20)]
    private string $triggerSource = self::TRIGGER_SCHEDULE;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    /** NULL = la ejecución no llegó a cerrarse (proceso muerto a mitad). */
    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** Código de salida del comando, NULL mientras no termina. */
    #[ORM\Column(name: 'exit_code', nullable: true)]
    private ?int $exitCode = null;

    /**
     * Resumen de una línea de lo ocurrido, para leerlo sin abrir la salida completa («interruptor
     * apagado», «0 destinatarios», el mensaje de la excepción…).
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $detail = null;

    /**
     * Salida del comando, recortada a {@see self::OUTPUT_MAX_LENGTH}. Vale tanto para las ejecuciones
     * manuales como para las del reloj: en un hosting incómodo, verla aquí ahorra bajarse
     * `var/log/cron.log`.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $output = null;

    public function __construct()
    {
        $this->startedAt = new \DateTimeImmutable();
    }

    /**
     * @return int|null identificador autogenerado
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string clave de la tarea en el manifiesto
     */
    public function getTaskKey(): string
    {
        return $this->taskKey;
    }

    /**
     * @param string $taskKey clave de la tarea en el manifiesto
     */
    public function setTaskKey(string $taskKey): self
    {
        $this->taskKey = $taskKey;

        return $this;
    }

    /**
     * @return string nombre del comando ejecutado
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * @param string $command nombre del comando ejecutado
     */
    public function setCommand(string $command): self
    {
        $this->command = $command;

        return $this;
    }

    /**
     * @return string estado de la ejecución (uno de los cuatro STATUS_*)
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $status uno de los cuatro STATUS_*
     */
    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return string origen del disparo (uno de los TRIGGER_*)
     */
    public function getTriggerSource(): string
    {
        return $this->triggerSource;
    }

    /**
     * @param string $triggerSource uno de los TRIGGER_*
     */
    public function setTriggerSource(string $triggerSource): self
    {
        $this->triggerSource = $triggerSource;

        return $this;
    }

    /**
     * @return \DateTimeImmutable instante en que arrancó la ejecución
     */
    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    /**
     * @param \DateTimeImmutable $startedAt instante de arranque
     */
    public function setStartedAt(\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    /**
     * @return \DateTimeImmutable|null instante de cierre, o null si no cerró
     */
    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /**
     * @param \DateTimeImmutable|null $finishedAt instante de cierre
     */
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    /**
     * @return int|null código de salida, o null si no terminó
     */
    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * @param int|null $exitCode código de salida del comando
     */
    public function setExitCode(?int $exitCode): self
    {
        $this->exitCode = $exitCode;

        return $this;
    }

    /**
     * @return string|null resumen de una línea, o null
     */
    public function getDetail(): ?string
    {
        return $this->detail;
    }

    /**
     * Guarda el resumen recortándolo al largo de la columna, para que un mensaje de excepción largo
     * no reviente el INSERT que está intentando dejar constancia del fallo.
     *
     * @param string|null $detail resumen de una línea
     */
    public function setDetail(?string $detail): self
    {
        $this->detail = null === $detail ? null : mb_substr(trim($detail), 0, 255);

        return $this;
    }

    /**
     * @return string|null salida recortada del comando, o null
     */
    public function getOutput(): ?string
    {
        return $this->output;
    }

    /**
     * Guarda la salida recortada a {@see self::OUTPUT_MAX_LENGTH}, quedándose con el FINAL: el
     * resumen del comando y la traza de un fallo salen al final, no al principio.
     *
     * @param string|null $output salida completa del comando
     */
    public function setOutput(?string $output): self
    {
        $output = null === $output ? null : trim($output);

        if (null !== $output && mb_strlen($output) > self::OUTPUT_MAX_LENGTH) {
            $output = "…(salida recortada)\n".mb_substr($output, -self::OUTPUT_MAX_LENGTH);
        }

        $this->output = $output;

        return $this;
    }

    /**
     * ¿Terminó la ejecución? Una fila sin cierre es un proceso que murió a mitad.
     *
     * @return bool true si la ejecución llegó a cerrarse
     */
    public function isFinished(): bool
    {
        return null !== $this->finishedAt;
    }

    /**
     * @return int|null duración en segundos, o null si no terminó
     */
    public function getDurationSeconds(): ?int
    {
        return null === $this->finishedAt
            ? null
            : $this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }
}
