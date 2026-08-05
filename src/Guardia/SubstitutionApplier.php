<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\Substitution;
use App\Entity\User;
use App\Repository\BreakDutyAssignmentRepository;
use App\Repository\GuardiaCoverRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\SubstitutionRepository;
use App\Service\GuardiaAssignmentNotifier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Aplica y deshace una sustitución de baja larga ({@see Substitution}): mueve el horario, el cuadrante
 * de recreo y las guardias ya asignadas de la persona sustituida a quien la cubre.
 *
 * **Se mueven las filas.** No hay una capa que traduzca "titular" a "sustituto" al leer, y es
 * deliberado: el horario es una rejilla semanal sin fechas ({@see \App\Entity\ScheduleEntry}), y las ocho
 * lecturas por profesor de {@see ScheduleEntryRepository} piden y devuelven cosas de tres formas
 * distintas —un {@see User} de entrada, entidades con su profesor dentro, mapas indexados por id—, así
 * que no existe un punto único donde interceptar la traducción. Moviendo las filas, ni esas ocho
 * consultas ni ninguna pantalla se enteran: el horario simplemente dice quién está de verdad.
 *
 * Lo que ese diseño no puede hacer, y la pantalla dice en voz alta: **reconstruir el pasado**. Dar de
 * alta hoy una baja que empezó hace dos semanas no cambia esas dos semanas de partes. Tampoco hace
 * falta — el parte materializa profesor, grupo, aula y materia al registrar la ausencia, así que los
 * días pasados no se recalculan nunca.
 *
 * Y lo que sí exige: **reaplicarse después de cada cosa que regenere el horario**, que son dos y no una
 * (ver {@see withSubstitutionsSuspended()}).
 */
final class SubstitutionApplier
{
    public function __construct(
        private readonly ScheduleEntryRepository $schedule,
        private readonly BreakDutyAssignmentRepository $breakDuties,
        private readonly GuardiaCoverRepository $covers,
        private readonly SubstitutionRepository $substitutions,
        private readonly GuardiaAssignmentNotifier $notifier,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Da de alta la sustitución y traspasa: el horario del curso, las plazas del cuadrante de recreo y
     * las guardias que ya estuvieran asignadas de hoy en adelante.
     *
     * Todo en una transacción: el alta, el horario, el cuadrante de recreo y las guardias del futuro son
     * un solo hecho. A medias sería lo peor de los dos mundos — un horario movido a nombre de alguien sin
     * ningún sitio donde conste que se movió, o una sustitución que la pantalla da por hecha con media
     * jornada todavía en el sitio anterior. Y el único paso que puede fallar de verdad, el {@code flush}
     * de las guardias, cerraría el EntityManager y dejaría sin salida a cualquier intento de arreglarlo
     * dentro de la misma petición.
     *
     * Los avisos se mandan FUERA, ya con todo confirmado: enviar correos y push dentro de una transacción
     * es prometer algo que un rollback posterior desmiente.
     *
     * Lo que sigue sin poder garantizar: dos altas simultáneas sobre la misma persona. Las comprobaciones
     * leen y luego escriben, y no hay UNIQUE que las respalde (en MariaDB dos NULL no colisionan, ver la
     * migración). Hacen falta dos envíos en los mismos milisegundos desde la misma pantalla del TIC.
     *
     * @param Substitution       $substitution la sustitución a abrir, ya rellena y sin persistir
     * @param \DateTimeImmutable $today        el día de hoy, que acota hasta dónde llegan las guardias
     *                                         traspasadas
     *
     * @return SubstitutionResult qué se movió
     *
     * @throws SubstitutionRefused si las dos personas son la misma, si alguna está ya en una sustitución
     *                             sin cerrar, o si quien va a sustituir tiene horario o cuadrante propios
     */
    public function open(Substitution $substitution, \DateTimeImmutable $today): SubstitutionResult
    {
        $this->refuseIfUnsound($substitution);

        /** @var array{0: SubstitutionResult, 1: list<GuardiaCover>} $done */
        $done = $this->em->wrapInTransaction(function () use ($substitution, $today): array {
            $this->em->persist($substitution);
            $this->em->flush();

            $moved = $this->transfer(
                $substitution->getAcademicYear(),
                $substitution->getSubstitutedTeacher(),
                $substitution->getSubstitute(),
            );
            $handed = $this->handOverAssignedGuardias($substitution, $today);

            return [$moved, $handed];
        });

        [$moved, $handed] = $done;
        foreach ($handed as $cover) {
            $this->notifier->notifyAssigned($cover);
        }

        return new SubstitutionResult($moved->timetableCells, $moved->breakDutyPlaces, \count($handed));
    }

    /**
     * Cierra la sustitución y devuelve el horario y el cuadrante de recreo a la persona que volvió.
     *
     * Las guardias ya asignadas NO se devuelven: un cover es historia desde que se escribe, y las que
     * cambiaron de manos al abrir o ya se hicieron, o son de días que quien sustituyó estuvo aquí.
     *
     * En una transacción, como {@see open()}: devolver el horario y sellar la fecha de fin son el mismo
     * hecho. Separados, un fallo entre medias dejaría una sustitución que la pantalla sigue dando por
     * viva con el horario ya devuelto, y cerrarla otra vez lo movería en la dirección contraria.
     *
     * @param Substitution       $substitution la sustitución en vigor
     * @param \DateTimeImmutable $on           el día en que se cierra
     *
     * @return SubstitutionResult qué volvió a su sitio
     */
    public function close(Substitution $substitution, \DateTimeImmutable $on): SubstitutionResult
    {
        /** @var SubstitutionResult $returned */
        $returned = $this->em->wrapInTransaction(function () use ($substitution, $on): SubstitutionResult {
            $moved = $this->transfer(
                $substitution->getAcademicYear(),
                $substitution->getSubstitute(),
                $substitution->getSubstitutedTeacher(),
            );

            $substitution->setEndedOn($on);
            $this->em->flush();

            return $moved;
        });

        return $returned;
    }

    /**
     * Ejecuta algo que REGENERA el horario de un curso con las sustituciones de ese curso deshechas, y
     * las vuelve a aplicar al terminar.
     *
     * Hace falta en **un solo sitio**, el reimport de Peñalara, y ahí sin esto la sustitución no se
     * pierde: se DUPLICA. {@see ScheduleEntryRepository::replaceForTeachers()} borra por "profesor IN (…)
     * AND source = penalara", y la lista de profesores sale del export, que sigue nombrando a la persona
     * de baja —su código de Peñalara no cambia porque esté ausente—. Con las filas ya a nombre de quien
     * sustituye, ese borrado no encuentra nada y el import inserta un juego nuevo para la persona de
     * baja: quedan las dos en el pool de guardias con el mismo horario, sin un solo error por ningún
     * lado. Devolviendo antes y volviendo a traspasar después, el reimport vuelve a ser idempotente.
     *
     * **La re-propuesta del cuadrante NO lo necesita**, aunque también regenere celdas de horario, y
     * conviene decir por qué para que nadie lo "arregle": {@see RotaPlanner::publish()} borra por "curso
     * + source = engine" sin filtrar por persona, y tanto sus candidatos como los de
     * {@see BreakRotaPlanner} salen de quien tiene horario AHORA
     * ({@see ScheduleEntryRepository::teachersWithEntries()}) — o sea, de quien sustituye. Lectura y
     * escritura hablan de la misma persona, así que el cuadrante se propone y se publica directamente a
     * su nombre, y cerrar la sustitución lo devuelve con el resto. Suspender ahí sería peor: propondría
     * el cuadrante para alguien que no está en el centro.
     *
     * Lo único que esas dos necesitan es el CUPO, que sí es del puesto y no de quien lo ocupa esa
     * semana; se hereda al leerlo ({@see \App\Repository\GuardiaQuotaRepository::findEffectiveByTeacher()})
     * en vez de copiar la fila, por lo mismo que el contador de guardias.
     *
     * La reaplicación va en un {@code finally}: si el trabajo revienta, el horario tiene que volver a su
     * sitio igualmente. La única salida sin reaplicar es que el fallo haya cerrado el EntityManager, y
     * entonces intentarlo solo taparía la excepción de verdad con otra; queda la sustitución abierta con
     * el horario devuelto, que es visible en pantalla y se arregla cerrándola y volviéndola a abrir.
     *
     * @template T
     *
     * @param AcademicYear     $year el curso que se va a regenerar
     * @param callable(): T    $work lo que regenera el horario
     *
     * @return T lo que devuelva el trabajo
     */
    public function withSubstitutionsSuspended(AcademicYear $year, callable $work): mixed
    {
        $open = $this->substitutions->findOpenFor($year);
        foreach ($open as $substitution) {
            $this->transfer($year, $substitution->getSubstitute(), $substitution->getSubstitutedTeacher());
        }

        try {
            return $work();
        } finally {
            if ($this->em->isOpen()) {
                foreach ($open as $substitution) {
                    $this->transfer($year, $substitution->getSubstitutedTeacher(), $substitution->getSubstitute());
                }
            }
        }
    }

    /**
     * Mueve el horario y el cuadrante de recreo de una persona a otra dentro de un curso. La pieza que
     * comparten abrir, cerrar y suspender: las tres son el mismo gesto en una dirección o en la otra, y
     * tenerlo escrito una sola vez es lo que impide que devolver deje algo atrás que traspasar sí movía.
     *
     * @param AcademicYear $year el curso
     * @param User         $from de quién salen las filas
     * @param User         $to   a quién van
     *
     * @return SubstitutionResult las celdas y plazas movidas
     */
    private function transfer(AcademicYear $year, User $from, User $to): SubstitutionResult
    {
        return new SubstitutionResult(
            $this->schedule->moveTeacherEntries($year, $from, $to),
            $this->breakDuties->movePlaces($year, $from, $to),
        );
    }

    /**
     * Pasa a quien sustituye las guardias que ya estuvieran asignadas a la persona de baja, y hace que
     * sus avisos vuelvan a salir.
     *
     * Acotado por los dos extremos. Nunca antes de HOY, pase lo que pase con la fecha de la baja: los
     * días pasados son el histórico del parte y no se reescriben. Y nunca antes del comienzo de la baja,
     * porque una baja que empieza el lunes no cambia la guardia del viernes anterior.
     *
     * Las que ya se cerraron como incidencia ("nadie la cubrió") se dejan quietas: ese hecho tiene fecha
     * y dueño, y traspasarlo cambiaría a quién no cubrió.
     *
     * El cambio de persona va por el ORM y no en bloque, al revés que los sellos: quién cubre una
     * guardia lo decide alguien, y {@see GuardiaCover} es {@see \App\Contract\Auditable} justo para que
     * eso quede en el historial. Los sellos de aviso son contabilidad de la máquina y se borran en
     * bloque — si no se borraran, quien hereda la guardia no recibiría ningún recordatorio, porque ya
     * salieron a nombre de la otra persona.
     *
     * Devuelve los covers en vez de avisar aquí mismo: el borrado de sellos es una actualización en
     * bloque, así que estos objetos quedan con los valores viejos en memoria, y avisar es además algo que
     * no se puede deshacer si la transacción que envuelve todo esto se cae. Lo hace {@see open()}, ya
     * fuera y con todo confirmado.
     *
     * @param Substitution       $substitution la sustitución recién abierta
     * @param \DateTimeImmutable $today        el día de hoy
     *
     * @return list<GuardiaCover> las guardias que cambiaron de manos, para avisar de ellas después
     */
    private function handOverAssignedGuardias(Substitution $substitution, \DateTimeImmutable $today): array
    {
        $startedOn = $substitution->getStartedOn();
        $from = $startedOn > $today ? $startedOn : $today;

        $handed = array_values(array_filter(
            $this->covers->findUpcomingAssignedTo($substitution->getSubstitutedTeacher(), $from),
            static fn (GuardiaCover $cover): bool => !$cover->isNotCovered(),
        ));
        if ([] === $handed) {
            return [];
        }

        foreach ($handed as $cover) {
            $cover->setAssignedGuardia($substitution->getSubstitute());
        }
        $this->em->flush();

        $this->covers->clearReminderStamps(array_map(static fn (GuardiaCover $c): int => (int) $c->getId(), $handed));

        return $handed;
    }

    /**
     * Comprueba lo que haría del traspaso un destrozo silencioso, antes de tocar una sola fila.
     *
     * @param Substitution $substitution la sustitución que se quiere abrir
     *
     * @throws SubstitutionRefused con la frase que explica cuál de los cuatro casos es
     */
    private function refuseIfUnsound(Substitution $substitution): void
    {
        $substituted = $substitution->getSubstitutedTeacher();
        $substitute = $substitution->getSubstitute();

        if ($substituted->getId() === $substitute->getId()) {
            throw SubstitutionRefused::samePerson($substituted);
        }

        foreach ([$substituted, $substitute] as $person) {
            $alreadyOpen = $this->substitutions->findOpenInvolving($person);
            if (null !== $alreadyOpen) {
                throw SubstitutionRefused::alreadyInvolved($person, $alreadyOpen);
            }
        }

        // Quien sustituye tiene que llegar sin nada propio en este curso: es lo que hace que el traspaso
        // sea reversible fila a fila y que el UNIQUE del cuadrante de recreo no pueda saltar a medias.
        $cells = \count($this->schedule->findByTeacherAndYear($substitution->getAcademicYear(), $substitute));
        if ($cells > 0) {
            throw SubstitutionRefused::substituteHasTimetable($substitute, $cells);
        }

        $places = \count($this->breakDuties->findByTeacher($substitution->getAcademicYear(), $substitute));
        if ($places > 0) {
            throw SubstitutionRefused::substituteHasBreakDuties($substitute, $places);
        }
    }
}
