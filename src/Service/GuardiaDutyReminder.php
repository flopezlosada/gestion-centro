<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\GuardiaReminderTrigger;
use App\Repository\AcademicYearRepository;
use App\Repository\GuardiaCoverRepository;
use App\Repository\ScheduleEntryRepository;
use App\Util\SchoolYear;

/**
 * El **doble recordatorio de guardia** que pidió el equipo directivo (30-07-2026): un aviso **la tarde
 * anterior** y otro **esa misma mañana**. Va desde el mismo barrido de cada pocos minutos que el resto de
 * recordatorios ({@see \App\Controller\CronController}).
 *
 * ### Por qué dos, y por qué UN aviso por persona en cada uno
 * Los dos momentos sirven para cosas distintas: el de la tarde anterior te deja preparar la clase desde
 * casa (ver si el grupo tiene tarea, coger una del banco), y el de la mañana es el que evita que se te pase
 * con el día ya empezado. Y en cada disparo sale **un solo aviso por persona**, con todas sus guardias de
 * ese día dentro: quien tiene tres guardias el jueves no necesita tres notificaciones, necesita saber
 * cuáles son. El requisito literal del centro —"que no reciba dos avisos por la misma guardia en el mismo
 * momento"— se cumple por construcción, porque cada guardia lleva su sello por disparo
 * ({@see GuardiaReminderTrigger::stampField()}).
 *
 * Es **autorreparable**: una guardia que aparezca después de que haya salido el aviso (una falta apuntada a
 * las nueve de la noche) no está sellada, así que entra en la siguiente pasada mientras la ventana siga
 * abierta, y las que ya se anunciaron no se repiten.
 *
 * ### Las dos ventanas, y por qué son horas y no un ajuste
 * "La tarde anterior" es de {@see EVENING_FROM_HOUR} en adelante, y "esa misma mañana" va de
 * {@see MORNING_FROM_HOUR} hasta que empieza la primera hora del centro (la del marco horario importado:
 * un aviso a las 10:30 diciendo "hoy tienes guardia a 2.ª" llega tarde para lo que sirve). No hay ajuste en
 * pantalla porque nadie lo ha pedido y un ajuste que nadie toca es una pantalla más que mantener; si el
 * centro quiere otras horas, se cambian estas dos constantes.
 *
 * ### Entrega
 * Móvil y campana, nunca correo ({@see NotificationDispatcher::PUSH_ONLY_KINDS}). El aviso de que te
 * asignan una guardia YA sale por correo cuando se asigna ({@see GuardiaAssignmentNotifier}); repetirlo dos
 * veces más por correo triplicaría el volumen de e-mail del módulo sin decir nada nuevo, y el centro ya se
 * queja del correo. Quien prefiera correo lo tiene en sus ajustes de avisos, por temas.
 *
 * ### Zona horaria
 * Como {@see GuardiaRaicesReminder} y {@see EventReminderNotifier}, compara horas de reloj: quien llame
 * tiene que pasar un "now" en la zona por defecto de PHP. Forzar otra desplazaría las dos ventanas.
 */
final readonly class GuardiaDutyReminder
{
    /** Desde qué hora se considera "la tarde anterior" (24 h). */
    private const int EVENING_FROM_HOUR = 18;

    /** Desde qué hora se considera "esa misma mañana" (24 h). */
    private const int MORNING_FROM_HOUR = 7;

    /**
     * Hasta qué hora llega la ventana de la mañana cuando el curso no tiene horario importado y por tanto
     * no se sabe a qué hora empieza el centro. Nunca se avisa fuera de la mañana por no saberlo.
     */
    private const int MORNING_FALLBACK_UNTIL_HOUR = 9;

    public function __construct(
        private GuardiaCoverRepository $covers,
        private ScheduleEntryRepository $schedule,
        private AcademicYearRepository $years,
        private NotificationDispatcher $dispatcher,
    ) {
    }

    /**
     * Manda los recordatorios que toquen en este instante: ninguno, el de la tarde anterior o el de la
     * mañana.
     *
     * Los sellos se ponen DESPUÉS de enviar, como en {@see GuardiaRaicesReminder}: si el proceso muere en
     * medio, el peor caso es un aviso repetido dentro de la misma ventana, que es mejor que perderlo. Al
     * revés se cambiaría un duplicado inocuo por un recordatorio que no llega.
     *
     * @param \DateTimeImmutable $now el instante del barrido, en la zona por defecto de PHP (ver la clase)
     *
     * @return int cuántos avisos han salido (uno por persona y disparo, no por guardia)
     */
    public function sendDue(\DateTimeImmutable $now): int
    {
        $trigger = $this->triggerDueAt($now);
        if (null === $trigger) {
            return 0;
        }

        $day = $now->setTime(0, 0)->modify(sprintf('+%d day', $trigger->daysAhead()));
        $slotTimes = $this->slotTimes($day);

        // Agrupado por persona: un aviso con todas sus guardias del día, no uno por guardia.
        /** @var array<int, array{teacher: User, covers: list<GuardiaCover>}> $byTeacher */
        $byTeacher = [];
        /** @var list<int> $stamped */
        $stamped = [];
        foreach ($this->covers->findRemindableOn($day, $trigger) as $cover) {
            $teacher = $cover->getAssignedGuardia();
            $id = $cover->getId();
            // No puede ser null: findRemindableOn hace INNER JOIN sobre assignedGuardia. La comprobación
            // se queda porque el getter SÍ es nullable — defensiva de tipos, no de datos.
            if (null === $teacher || null === $teacher->getId() || null === $id) {
                continue;
            }

            $byTeacher[(int) $teacher->getId()] ??= ['teacher' => $teacher, 'covers' => []];
            $byTeacher[(int) $teacher->getId()]['covers'][] = $cover;
            $stamped[] = $id;
        }

        /** @var list<Notification> $notifications */
        $notifications = [];
        foreach ($byTeacher as $row) {
            $notifications[] = $this->dispatcher->record(
                $row['teacher'],
                'guardia.reminder',
                $this->titleFor($trigger, \count($row['covers'])),
                $this->bodyFor($trigger, $row['covers'], $slotTimes),
            );
        }

        $this->dispatcher->flushAndSend($notifications);
        $this->covers->markReminderSent($stamped, $trigger, $now);

        return \count($notifications);
    }

    /**
     * Qué disparo toca en este instante, si alguno.
     *
     * Se evalúan los dos y gana el primero que encaje: hoy sus ventanas no se solapan (la tarde empieza
     * mucho después de la primera hora), y preguntarlo así evita que un cambio en las constantes convierta
     * un solape en dos avisos silenciosos a la vez.
     *
     * @param \DateTimeImmutable $now el instante del barrido
     *
     * @return GuardiaReminderTrigger|null el disparo que toca, o null fuera de las dos ventanas
     */
    private function triggerDueAt(\DateTimeImmutable $now): ?GuardiaReminderTrigger
    {
        $hour = (int) $now->format('G');
        if ($hour >= self::EVENING_FROM_HOUR) {
            return GuardiaReminderTrigger::EVENING;
        }
        if ($hour >= self::MORNING_FROM_HOUR && $now < $this->schoolDayStart($now)) {
            return GuardiaReminderTrigger::MORNING;
        }

        return null;
    }

    /**
     * A qué hora empieza el centro el día del barrido, según el horario importado del curso al que pertenece
     * ese día. Sin horario no se sabe, y en ese caso la ventana de la mañana se cierra a
     * {@see MORNING_FALLBACK_UNTIL_HOUR}: el aviso puede llegar algo tarde, pero nunca a media mañana.
     *
     * @param \DateTimeImmutable $now el instante del barrido
     *
     * @return \DateTimeImmutable el instante en que arranca la jornada de ese día
     */
    private function schoolDayStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $times = $this->slotTimes($now);
        if ([] === $times) {
            return $now->setTime(self::MORNING_FALLBACK_UNTIL_HOUR, 0);
        }

        // El primer tramo por índice, no por hora: el índice 0 es la primera hora del día en Peñalara.
        ksort($times);
        $first = array_values($times)[0]['startsAt'];

        return $now->setTime((int) $first->format('G'), (int) $first->format('i'));
    }

    /**
     * Las horas de los tramos del curso al que pertenece una fecha, o un array vacío si ese curso no tiene
     * horario importado.
     *
     * @param \DateTimeImmutable $date el día
     *
     * @return array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> horas por tramo
     */
    private function slotTimes(\DateTimeImmutable $date): array
    {
        $year = $this->years->findBySchoolYear(SchoolYear::current($date));

        return $this->schedule->slotTimes($year instanceof AcademicYear ? $year : null);
    }

    /**
     * El título: dice el día y cuántas son, que es lo que se lee en la pantalla de bloqueo del móvil sin
     * abrir nada.
     *
     * @param GuardiaReminderTrigger $trigger el disparo
     * @param int                    $count   cuántas guardias lleva ese día
     *
     * @return string el título del aviso
     */
    private function titleFor(GuardiaReminderTrigger $trigger, int $count): string
    {
        return 1 === $count
            ? sprintf('%s tienes guardia', $trigger->dayWord())
            : sprintf('%s tienes %d guardias', $trigger->dayWord(), $count);
    }

    /**
     * El cuerpo: una línea por guardia con la hora, el grupo, el aula DONDE OCURRE DE VERDAD
     * ({@see GuardiaCover::effectiveRoomName()}, que es la de la agrupación si se juntaron varios grupos) y
     * a quién se cubre; y el aviso de si el grupo tiene tarea o no, que es justamente lo que se puede
     * resolver con antelación.
     *
     * @param GuardiaReminderTrigger                                                        $trigger   el disparo
     * @param list<GuardiaCover>                                                            $covers    sus guardias de ese día, por tramo
     * @param array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}>    $slotTimes horas por tramo
     *
     * @return string el cuerpo del aviso
     */
    private function bodyFor(GuardiaReminderTrigger $trigger, array $covers, array $slotTimes): string
    {
        $lines = array_map(static function (GuardiaCover $cover) use ($slotTimes): string {
            $times = $slotTimes[$cover->getSlotIndex()] ?? null;
            $when = null !== $times ? $times['startsAt']->format('H:i') : sprintf('%d.ª hora', $cover->getSlotIndex() + 1);
            $where = implode(' · ', array_filter([$cover->getGroupName(), $cover->effectiveRoomName()]));

            return sprintf(
                '%s%s — cubres a %s.%s',
                $when,
                '' !== $where ? ' · '.$where : '',
                $cover->getAbsentTeacher()->getFullName(),
                $cover->hasTask() ? ' Tiene tarea.' : ' SIN tarea: puedes coger una del banco.',
            );
        }, $covers);

        return sprintf("%s:\n%s", GuardiaReminderTrigger::EVENING === $trigger ? 'Mañana te toca' : 'Hoy te toca', implode("\n", $lines));
    }
}
