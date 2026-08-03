<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\GuardiaSupport;
use App\Entity\ScheduleEntry;
use App\Entity\SpacePlan;
use App\Entity\SpacePlanAssignment;
use App\Entity\User;
use App\Enum\SubstitutionScope;
use App\Enum\Weekday;
use App\Repository\AbsenceRepository;
use App\Repository\GuardiaCoverRepository;
use App\Repository\GuardiaSupportRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\SpacePlanAssignmentRepository;
use App\Repository\SpacePlanRepository;
use App\Repository\UserRepository;
use App\Service\GuardiaAssignmentNotifier;
use App\Space\ApprovedPlans;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La semana de exámenes de 2º de Bachillerato, vista desde las guardias. Petición literal del centro
 * (30-07-2026): *"las guardias del profesorado acompañante las cubren los compañeros de 2º de Bach, leyendo
 * sus horarios; el programa propone y el equipo directivo valida o retoca"*.
 *
 * ### La aritmética, que es lo único que hace falta entender
 * Durante los exámenes, los grupos de 2º de Bach no tienen sus clases (eso ya lo sabe la aplicación: es un
 * {@see SpacePlan} aprobado con {@see \App\Enum\SubstitutionScope::GROUPS}). De ahí salen dos consecuencias
 * opuestas a la misma hora:
 *  - **quien acompaña un examen no puede hacer su guardia** — está en el aula del examen, y hasta ahora el
 *    reparto se la seguía dando porque ni falta ni tiene clase;
 *  - **quien da clase SOLO a esos grupos se queda libre** — y es exactamente quien puede cogerla.
 *
 * Así que no hay nada que inventar: hay que pasar unas guardias de unas manos a otras. Este servicio lo
 * PROPONE ({@see proposeFor()}) y lo aplica cuando alguien lo valida ({@see apply()}).
 *
 * ### Por qué no hay entidad nueva ni interruptor nuevo
 * Quien acompaña se DEDUCE del plan aprobado (una línea de actividad con profesor), no se guarda otra vez; y
 * a quien queda libre se le da de alta como {@see GuardiaSupport}, que ya significa literalmente esto — "el
 * horario dice que da clase y la realidad dice que no" — y ya sale en el panel del parte, en la hoja de
 * asignación y en la banda SUPPORT del motor, y ya se puede deshacer. Y la "pestaña activable" que pedía el
 * centro es el propio plan aprobado: un segundo interruptor para lo mismo solo serviría para quedarse
 * desparejado del primero.
 *
 * Ojo con lo que NO decide: qué grupos se examinan y quién acompaña cada examen es del módulo de espacios
 * ({@see \App\Space\StaffScheduler}). Aquí solo se leen.
 */
final class ExamPeriodRelief
{
    public function __construct(
        private readonly SpacePlanRepository $plans,
        private readonly SpacePlanAssignmentRepository $activities,
        private readonly ScheduleEntryRepository $schedule,
        private readonly GuardiaCoverRepository $covers,
        private readonly GuardiaSupportRepository $support,
        private readonly AbsenceRepository $absences,
        private readonly UserRepository $users,
        private readonly GuardiaScheduler $scheduler,
        private readonly GuardiaAssignmentNotifier $notifier,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * La propuesta para un día: hora por hora, quién acompaña un examen (con las guardias que hay que
     * quitarle) y a quién dejan libre los exámenes.
     *
     * @param AcademicYear       $year the course the date falls into (supplies the timetable)
     * @param \DateTimeImmutable $date the day
     *
     * @return ExamPeriodProposal la propuesta; {@see ExamPeriodProposal::isActive()} dice si ese día hay
     *                            exámenes en marcha
     */
    public function proposeFor(AcademicYear $year, \DateTimeImmutable $date): ExamPeriodProposal
    {
        $plans = $this->substitutingPlansOn($date);
        if ([] === $plans) {
            return new ExamPeriodProposal([], []);
        }

        $approved = new ApprovedPlans($plans);
        $weekday = Weekday::from((int) $date->format('N'));
        $slotTimes = $this->schedule->slotTimes($year);

        $periods = [];
        foreach ($this->schedule->distinctSlots($year) as $slot) {
            $slotIndex = $slot['index'];
            if (!self::anyPlanCovers($plans, $date, $slotIndex)) {
                continue;
            }

            $supervising = $this->activities->supervisedActivitiesAt($date, $slotIndex);
            $parte = $this->covers->findForParte($date, $slotIndex);

            $times = $slotTimes[$slotIndex] ?? null;
            $periods[] = new ExamPeriodSlot(
                $slotIndex,
                null !== $times ? $times['startsAt']->format('H:i').'–'.$times['endsAt']->format('H:i') : null,
                $this->supervisingRows($supervising, $parte),
                $this->freedRows($year, $approved, $date, $weekday, $slotIndex, array_keys($supervising)),
                \count(array_filter($parte, static fn (GuardiaCover $c): bool => null === $c->getAssignedGuardia())),
            );
        }

        return new ExamPeriodProposal($plans, $periods);
    }

    /**
     * Aplica lo validado: da de alta como apoyo a quien se haya marcado, retira las guardias de quien
     * acompaña un examen y vuelve a repartir las horas afectadas.
     *
     * Las tres cosas van juntas y en este orden a propósito. Dar de alta el apoyo primero es lo que hace que
     * el reparto tenga a quién recurrir; retirar la guardia después es lo que la deja libre; y repartir al
     * final es lo que la coloca. Al revés, el reparto se ejecutaría sin nadie disponible y el grupo se
     * quedaría sin cubrir aunque hubiera media docena de personas libres.
     *
     * Las guardias se retiran en TODAS las horas que cubre el plan, también en las que no se haya marcado a
     * nadie. No es un descuido: quien está en un examen no puede hacer esa guardia, y dejarla puesta sería
     * apuntar en el parte a alguien que no va a aparecer. Si no hay quien la coja, la línea se queda sin
     * cubrir — que es la verdad, y sale avisada en el parte.
     *
     * Se aplica sobre la propuesta RECALCULADA aquí, no sobre lo que traiga el formulario: la pantalla se
     * pintó antes y entre medias puede haber cambiado todo (a alguien lo han apuntado de baja, otra persona
     * ya dio de alta el apoyo). Lo que llega del formulario es solo un filtro de "a estos sí" sobre lo que
     * el programa vuelve a proponer, así que nadie acaba de alta como apoyo por una pantalla vieja ni con
     * una nota que dice que está libre por los exámenes cuando no lo está.
     *
     * @param AcademicYear          $year          the course the date falls into
     * @param \DateTimeImmutable    $date          the day
     * @param array<int, list<int>> $supportBySlot slot index → los ids del profesorado a dar de alta como apoyo
     *
     * @return array{support: int, handedOver: int, assigned: int, refused: list<string>} qué se hizo, y a
     *                                                                                   quién se rechazó y por qué
     *
     * @throws UniqueConstraintViolationException cuando otra persona aplica la misma propuesta en el mismo
     *                                           instante (el UNIQUE de {@see GuardiaSupport} es la última
     *                                           palabra). Doctrine cierra el gestor de entidades, así que
     *                                           quien llame tiene que convertirlo en "vuelve a intentarlo"
     */
    public function apply(AcademicYear $year, \DateTimeImmutable $date, array $supportBySlot): array
    {
        $proposal = $this->proposeFor($year, $date);
        if (!$proposal->isActive()) {
            return ['support' => 0, 'handedOver' => 0, 'assigned' => 0, 'refused' => []];
        }

        $note = self::freedNote($proposal->plans);
        $refused = [];
        $touchedSlots = [];
        $signedUp = 0;
        /** @var list<array{cover: GuardiaCover, teacher: User}> $handedOver */
        $handedOver = [];

        foreach ($proposal->periods as $slot) {
            $ticked = $supportBySlot[$slot->slotIndex] ?? [];

            // 1. El apoyo, y solo entre quien la propuesta recién calculada dice que está libre.
            $offered = [];
            foreach ($slot->freed as $row) {
                $id = (int) $row['teacher']->getId();
                $offered[] = $id;
                if (!\in_array($id, $ticked, true)) {
                    continue;
                }
                if ($row['alreadySupport']) {
                    $refused[] = sprintf('%s ya estaba dado de alta como apoyo a las %s.', $row['teacher']->getFullName(), $slot->label());
                    continue;
                }

                $this->em->persist((new GuardiaSupport())
                    ->setTeacher($row['teacher'])
                    ->setDate($date)
                    ->setSlotIndex($slot->slotIndex)
                    ->setNote($note));
                ++$signedUp;
                $touchedSlots[$slot->slotIndex] = true;
            }

            // Marcado en la pantalla pero fuera de la propuesta de ahora: se dice con nombre, nunca se
            // traga. Quien validó creería que ese hueco está resuelto.
            $stale = array_values(array_diff($ticked, $offered));
            if ([] !== $stale) {
                foreach ($this->users->findBy(['id' => $stale]) as $teacher) {
                    $refused[] = sprintf(
                        '%s no está entre quien queda libre a las %s (ya tiene guardia a esa hora, acompaña una actividad o falta): no se ha dado de alta.',
                        $teacher->getFullName(),
                        $slot->label(),
                    );
                }
            }

            // 2. Las guardias de quien acompaña, fuera. Se recogen antes de vaciar para poder avisar después
            //    del commit: decirle a alguien que su guardia ha cambiado y luego no guardarlo sería lo peor.
            foreach ($slot->supervising as $row) {
                foreach ($row['covers'] as $cover) {
                    $cover->setAssignedGuardia(null);
                    $handedOver[] = ['cover' => $cover, 'teacher' => $row['teacher']];
                    $touchedSlots[$slot->slotIndex] = true;
                }
            }
        }

        $this->em->flush();

        // Avisado ya en firme. El motivo no viaja con el aviso, igual que en el resto del módulo: quien
        // recibe esto necesita saber que ya no le toca, y el porqué se lo cuenta la coordinación si procede.
        foreach ($handedOver as $row) {
            $this->notifier->notifyRelieved($row['cover'], $row['teacher']);
        }

        // 3. A repartir, ahora que el apoyo existe y las guardias están libres.
        $assigned = 0;
        foreach (array_keys($touchedSlots) as $slotIndex) {
            $assigned += $this->scheduler->autoAssign($year, $date, $slotIndex);
        }

        return ['support' => $signedUp, 'handedOver' => \count($handedOver), 'assigned' => $assigned, 'refused' => $refused];
    }

    /**
     * Los planes aprobados de ese día que de verdad sacan grupos del horario. Un cambio de aula a secas no
     * cuenta: nadie se queda libre por dar su clase en otra puerta.
     *
     * @param \DateTimeImmutable $date the day
     *
     * @return list<SpacePlan> the plans that replace somebody's timetable that day
     */
    private function substitutingPlansOn(\DateTimeImmutable $date): array
    {
        return array_values(array_filter(
            $this->plans->approvedCovering($date),
            static fn (SpacePlan $plan): bool => SubstitutionScope::NONE !== $plan->getSubstitutionScope(),
        ));
    }

    /**
     * Quién acompaña un examen a esa hora, con las guardias que tiene puestas y que hay que pasar a otra
     * persona.
     *
     * @param array<int, SpacePlanAssignment> $supervising teacher id → la línea del plan que le ocupa
     * @param list<GuardiaCover>              $parte       las líneas del parte de esa hora
     *
     * @return list<array{teacher: User, what: string, room: ?string, covers: list<GuardiaCover>}> una fila por persona, por nombre
     */
    private function supervisingRows(array $supervising, array $parte): array
    {
        $rows = [];
        foreach ($supervising as $teacherId => $line) {
            $teacher = $line->getTeacher();
            if (null === $teacher) {
                continue;
            }
            $rows[] = [
                'teacher' => $teacher,
                'what' => $line->getActivityTitle() ?? 'Examen',
                'room' => $line->getRoom()?->getCode(),
                'covers' => array_values(array_filter(
                    $parte,
                    static fn (GuardiaCover $c): bool => $c->getAssignedGuardia()?->getId() === $teacherId,
                )),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['teacher']->getFullName(), $b['teacher']->getFullName()));

        return $rows;
    }

    /**
     * A quién dejan libre los exámenes a esa hora: quien tiene clase entonces y TODOS sus grupos están
     * examinándose. "Todos" es la palabra que importa — quien a esa hora da 2ºBACH-A y también 1ºESO-C sigue
     * teniendo clase con los de 1º, y ofrecerlo como apoyo sería dejar a un grupo solo.
     *
     * Se quitan de la lista quienes no pueden cogerla de todas formas: los que acompañan un examen, los que
     * ya están en el cuadrante de guardias de esa hora (ya son candidatos, no hace falta darles de alta) y
     * los que faltan ese día.
     *
     * @param AcademicYear       $year           the course whose timetable to read
     * @param ApprovedPlans      $approved       los planes del día, para preguntar si un grupo se examina
     * @param \DateTimeImmutable $date           the day
     * @param Weekday            $weekday        the weekday
     * @param int                $slotIndex      the period index
     * @param list<int>          $supervisingIds ids de quien acompaña un examen a esa hora
     *
     * @return list<array{teacher: User, groups: list<string>, alreadySupport: bool}> una fila por persona, por nombre
     */
    private function freedRows(AcademicYear $year, ApprovedPlans $approved, \DateTimeImmutable $date, Weekday $weekday, int $slotIndex, array $supervisingIds): array
    {
        $onDuty = array_map(static fn (ScheduleEntry $e): int => (int) $e->getTeacher()->getId(), $this->schedule->dutyPoolAt($year, $weekday, $slotIndex));
        $absentIds = $this->absences->absentTeacherIdsAt($date, $slotIndex);
        $supportIds = array_map(static fn (GuardiaSupport $s): int => (int) $s->getTeacher()->getId(), $this->support->findForSlot($date, $slotIndex));

        $freedGroups = [];
        foreach ($this->schedule->lectiveGroupsByTeacherAt($year, $weekday, $slotIndex) as $teacherId => $groups) {
            if (\in_array($teacherId, $supervisingIds, true) || \in_array($teacherId, $onDuty, true) || \in_array($teacherId, $absentIds, true)) {
                continue;
            }
            $replaced = array_values(array_filter(
                $groups,
                static fn (string $group): bool => $approved->replaceTimetableFor($date, $slotIndex, $group),
            ));
            // Todos sus grupos de esa hora, no alguno: si le queda uno, le queda clase.
            if (\count($replaced) === \count($groups)) {
                $freedGroups[$teacherId] = array_values(array_unique($replaced));
            }
        }
        if ([] === $freedGroups) {
            return [];
        }

        $rows = [];
        foreach ($this->users->findBy(['id' => array_keys($freedGroups)]) as $teacher) {
            $rows[] = [
                'teacher' => $teacher,
                'groups' => $freedGroups[(int) $teacher->getId()],
                'alreadySupport' => \in_array((int) $teacher->getId(), $supportIds, true),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['teacher']->getFullName(), $b['teacher']->getFullName()));

        return $rows;
    }

    /**
     * Si alguno de los planes del día cubre esa hora.
     *
     * @param list<SpacePlan>    $plans     the day's plans
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index
     *
     * @return bool true when some plan is in force then
     */
    private static function anyPlanCovers(array $plans, \DateTimeImmutable $date, int $slotIndex): bool
    {
        foreach ($plans as $plan) {
            if ($plan->covers($date, $slotIndex)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La nota del apoyo, que es lo que el parte enseña junto al nombre para que se entienda de dónde sale
     * esa persona: el título del plan, no un "apoyo" a secas.
     *
     * @param list<SpacePlan> $plans the day's plans
     *
     * @return string the note
     */
    private static function freedNote(array $plans): string
    {
        return sprintf('Libre por %s', implode(' / ', array_map(static fn (SpacePlan $p): string => $p->getTitle(), $plans)));
    }
}
