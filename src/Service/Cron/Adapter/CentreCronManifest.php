<?php

declare(strict_types=1);

namespace App\Service\Cron\Adapter;

use App\Service\AppSettings;
use App\Service\Cron\CronManifest;
use App\Util\AppTime;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * El manifiesto de ESTE proyecto: qué barridos programados existen, cada cuánto y quién los apaga.
 *
 * Es la pieza que cada aplicación reescribe al trasplantar el planificador, y la única. Está en su
 * propia carpeta `Adapter/` precisamente para que la frontera se vea: lo de fuera se copia sin tocar,
 * lo de aquí se sustituye.
 *
 * LAS CADENCIAS VIVEN EN CÓDIGO, no en la tabla de ajustes, y es una decisión de este proyecto (la
 * interfaz permite lo contrario). No son un ajuste del centro: «cada cinco minutos» es una propiedad
 * del aviso —un push «10 minutos antes» necesita que alguien mire cada pocos minutos— y ponerla en una
 * pantalla invitaría a bajarla a «una vez al día», que rompería la función sin que nada avisara. Lo que
 * SÍ se puede tocar sin desplegar es el interruptor de cada tarea.
 *
 * LOS INTERRUPTORES vienen de `app_setting`, con el valor por defecto ENCENDIDO
 * ({@see AppSettings::isCronTaskEnabled()}). Encendido por defecto porque estos cinco barridos son la
 * razón de ser del planificador: una tarea que nace apagada por omisión reproduce exactamente la
 * avería que esto viene a cerrar. Para pausar una hace falta escribir la fila a propósito.
 *
 * `requires` va VACÍO en las cinco, y conviene entender por qué antes de copiar el patrón de otro
 * proyecto: aquí no hay un interruptor general de envíos que pudiera hacer que el mailer descartara en
 * silencio. Quién recibe y por qué canal se decide por persona ({@see \App\Service\NotificationDispatcher},
 * que además consulta el {@see \App\Security\AccessGate} antes de cualquier canal). Si algún día se
 * añade un interruptor maestro de correo, TIENE que entrar en el `requires` de todas: sin él, con el
 * maestro apagado la tarea se registraría como «hizo su trabajo» sin entregar nada.
 */
#[AsAlias(CronManifest::class)]
final class CentreCronManifest implements CronManifest
{
    /** Barrido diario de avisos de tareas (próximas, fuera de plazo) y purga de avisos caducados. */
    public const string CRON_TASK_REMINDERS = 'cron.task_reminders';

    /** Avisos de eventos de la agenda personal a punto de empezar. */
    public const string CRON_EVENT_REMINDERS = 'cron.event_reminders';

    /** Doble recordatorio de guardia: la tarde anterior y esa misma mañana. */
    public const string CRON_GUARDIA_DUTY_REMINDERS = 'cron.guardia_duty_reminders';

    /** «Apunta las ausencias en RAICES» a quien está cubriendo una guardia ahora mismo. */
    public const string CRON_GUARDIA_RAICES_REMINDERS = 'cron.guardia_raices_reminders';

    /** Aviso a las personas convocadas a una reunión que está a punto de empezar. */
    public const string CRON_MEETING_REMINDERS = 'cron.meeting_reminders';

    /** Poda del propio registro de ejecuciones, que a cadencia de minutos crece rápido. */
    public const string CRON_PURGE_LOG = 'cron.purge_log';

    /**
     * Cuántos minutos entre pasadas de los barridos con antelación en minutos.
     *
     * Cinco, porque es la antelación más fina que la aplicación ofrece («10 minutos antes» en la
     * agenda personal) y con este período un aviso sale de media a los dos minutos y medio. Es también
     * lo que condiciona el reloj: con cinco minutos, el `schedule` de GitHub Actions NO sirve (se
     * retrasa y descarta pasadas) y hace falta un reloj de verdad llamando al tick.
     */
    private const int MINUTE_LEVEL_CADENCE = 5;

    /**
     * Plazo de retraso de los barridos de minutos, en horas.
     *
     * TRES y no una fracción de hora, aunque la cadencia sea de cinco minutos, porque el plazo no mide
     * la cadencia: mide cuándo hay que dar la alarma. El reloj de respaldo (GitHub Actions) pasa cada
     * hora, así que con el reloj principal muerto estas tareas siguen corriendo cada hora — sano, y con
     * un plazo de una hora saldría en rojo. Tres horas absorben el respaldo y algún hipo del hosting, y
     * siguen cazando una caída de verdad el mismo día.
     */
    private const int MINUTE_LEVEL_MAX_DELAY_HOURS = 3;

    /**
     * El catálogo de tareas programadas.
     *
     * El ORDEN IMPORTA: el tick las ejecuta una detrás de otra tal y como están aquí. La purga va
     * última a propósito, para que en la misma pasada no borre el registro de lo que se acaba de
     * ejecutar.
     *
     * Ninguna declara `dry` porque ninguno de estos barridos sabe previsualizar: los notificadores
     * envían o no envían, no tienen un modo «cuéntame qué harías». Declararlo como que sí sería una
     * mentira que reventaría en cuanto alguien pulsara previsualizar.
     *
     * @var array<string, array<string, mixed>>
     */
    public const array TASKS = [
        self::CRON_TASK_REMINDERS => [
            'command' => 'app:tasks:send-reminders',
            // Por la mañana temprano, antes de la primera hora: quien tiene un plazo encima se lo
            // encuentra al llegar al centro, no a media tarde.
            'schedule' => ['freq' => 'daily', 'hour' => 7],
            // 36 h: da margen a un día entero perdido sin gritar por un retraso normal.
            'max_delay_hours' => 36,
            'requires' => [],
            'depends_on' => [],
            'needs_recipient' => false,
            'dry' => false,
        ],
        self::CRON_EVENT_REMINDERS => [
            'command' => 'app:events:send-reminders',
            'schedule' => ['freq' => 'interval', 'minutes' => self::MINUTE_LEVEL_CADENCE],
            'max_delay_hours' => self::MINUTE_LEVEL_MAX_DELAY_HOURS,
            'requires' => [],
            'depends_on' => [],
            'needs_recipient' => false,
            'dry' => false,
        ],
        self::CRON_GUARDIA_DUTY_REMINDERS => [
            'command' => 'app:guardias:send-duty-reminders',
            'schedule' => ['freq' => 'interval', 'minutes' => self::MINUTE_LEVEL_CADENCE],
            'max_delay_hours' => self::MINUTE_LEVEL_MAX_DELAY_HOURS,
            'requires' => [],
            'depends_on' => [],
            'needs_recipient' => false,
            'dry' => false,
        ],
        self::CRON_GUARDIA_RAICES_REMINDERS => [
            'command' => 'app:guardias:send-raices-reminders',
            'schedule' => ['freq' => 'interval', 'minutes' => self::MINUTE_LEVEL_CADENCE],
            'max_delay_hours' => self::MINUTE_LEVEL_MAX_DELAY_HOURS,
            'requires' => [],
            'depends_on' => [],
            'needs_recipient' => false,
            'dry' => false,
        ],
        self::CRON_MEETING_REMINDERS => [
            'command' => 'app:meetings:send-reminders',
            'schedule' => ['freq' => 'interval', 'minutes' => self::MINUTE_LEVEL_CADENCE],
            'max_delay_hours' => self::MINUTE_LEVEL_MAX_DELAY_HOURS,
            'requires' => [],
            'depends_on' => [],
            'needs_recipient' => false,
            'dry' => false,
        ],
        self::CRON_PURGE_LOG => [
            'command' => 'app:cron:purge-log',
            // De madrugada, cuando nadie está usando la aplicación y el registro del día ya está
            // completo.
            'schedule' => ['freq' => 'daily', 'hour' => 4],
            'max_delay_hours' => 36,
            'requires' => [],
            'depends_on' => [],
            'needs_recipient' => false,
            'dry' => false,
        ],
    ];

    /** Etiqueta legible de cada tarea, para los mensajes del gate y del registro. */
    private const array LABELS = [
        self::CRON_TASK_REMINDERS => 'Avisos diarios de tareas',
        self::CRON_EVENT_REMINDERS => 'Avisos de la agenda personal',
        self::CRON_GUARDIA_DUTY_REMINDERS => 'Recordatorios de guardia',
        self::CRON_GUARDIA_RAICES_REMINDERS => 'Avisos de RAICES',
        self::CRON_MEETING_REMINDERS => 'Avisos de reuniones',
        self::CRON_PURGE_LOG => 'Poda del registro de ejecuciones',
    ];

    public function __construct(
        private readonly AppSettings $settings,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function tasks(): array
    {
        return self::TASKS;
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled(string $settingKey): bool
    {
        return $this->settings->isCronTaskEnabled($settingKey);
    }

    /**
     * {@inheritDoc}
     */
    public function label(string $settingKey): string
    {
        return self::LABELS[$settingKey] ?? $settingKey;
    }

    /**
     * {@inheritDoc}
     *
     * La del centro, y por su nombre y no repetida a mano: {@see AppTime} es el único sitio donde está
     * escrita esa zona en toda la aplicación, precisamente para que no pueda haber dos respuestas a
     * «¿qué día es hoy?».
     */
    public function timezone(): string
    {
        return AppTime::ZONE;
    }
}
