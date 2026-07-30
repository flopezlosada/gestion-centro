<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GuardiaCover;
use App\Entity\GuardiaGrouping;
use App\Entity\ScheduleEntry;
use App\Entity\User;

/**
 * Avisa a quien afecta juntar varios grupos en un aula: al docente al que se le quita el aula (la
 * biblioteca o el salón de actos, que es de donde hay que sacar sitio) y al profesorado de guardia que
 * va a cubrirlos a todos juntos.
 *
 * El aviso al desplazado es un requisito explícito del centro y no un adorno: a esa persona nadie le ha
 * preguntado, se entera al llegar al aula y encontrarla ocupada. Por eso el mensaje dice de dónde a
 * dónde y por qué («por motivos organizativos»), y por eso deshacer la agrupación también avisa: la
 * clase vuelve a su sitio y quedarse con el primer mensaje sería peor que no haber avisado.
 *
 * Decide a quién avisar y qué decirle; la entrega (aviso in-app + e-mail + push) la hace
 * {@see NotificationDispatcher}, igual que el resto de notificadores.
 */
final class GuardiaRoomChangeNotifier
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {
    }

    /**
     * Avisa a los docentes cuya clase se queda sin aula porque la agrupación se la lleva. No avisa a
     * quien está ausente ese tramo: su clase es justo una de las que se están cubriendo, y no va a venir.
     *
     * @param GuardiaGrouping    $grouping  la agrupación que ocupa el aula
     * @param list<ScheduleEntry> $displaced las clases que estaban en esa aula a esa hora
     * @param list<int>          $absentIds ids de docentes ausentes ese tramo, a los que no se avisa
     * @param string|null        $timeLabel la hora en formato "08:25–09:20", si se conoce
     *
     * @return int a cuántas personas se avisó
     */
    public function notifyDisplaced(GuardiaGrouping $grouping, array $displaced, array $absentIds, ?string $timeLabel): int
    {
        $destination = null !== $grouping->getDisplacedToRoom()
            ? sprintf('Pasáis al aula %s.', $grouping->getDisplacedToRoom())
            : 'La coordinación de guardias te indicará dónde.';

        $sent = 0;
        foreach ($this->byTeacher($displaced, $absentIds) as [$teacher, $groups]) {
            $this->dispatcher->dispatch(
                $teacher,
                'room.changed',
                sprintf('Cambio de aula: %s', $grouping->getDate()->format('d/m/Y')),
                sprintf(
                    'Por motivos organizativos, el %s%s tu clase%s no se da en %s: se necesita esa aula para reunir varios grupos que se quedan sin profesor. %s%s',
                    $grouping->getDate()->format('d/m/Y'),
                    $this->at($timeLabel),
                    '' !== $groups ? sprintf(' de %s', $groups) : '',
                    $grouping->getRoomName(),
                    $destination,
                    $this->noteSuffix($grouping),
                ),
            );
            ++$sent;
        }

        return $sent;
    }

    /**
     * Avisa a los mismos docentes de que el cambio de aula queda anulado y su clase sigue donde estaba.
     *
     * @param GuardiaGrouping    $grouping  la agrupación que se deshace
     * @param list<ScheduleEntry> $displaced las clases que estaban en esa aula a esa hora
     * @param list<int>          $absentIds ids de docentes ausentes ese tramo, a los que no se avisa
     * @param string|null        $timeLabel la hora en formato "08:25–09:20", si se conoce
     *
     * @return int a cuántas personas se avisó
     */
    public function notifyDisplacementCancelled(GuardiaGrouping $grouping, array $displaced, array $absentIds, ?string $timeLabel): int
    {
        $sent = 0;
        foreach ($this->byTeacher($displaced, $absentIds) as [$teacher, $groups]) {
            $this->dispatcher->dispatch(
                $teacher,
                'room.changed',
                sprintf('Cambio de aula anulado: %s', $grouping->getDate()->format('d/m/Y')),
                sprintf(
                    'Se anula el cambio: el %s%s tu clase%s se da en %s como siempre.',
                    $grouping->getDate()->format('d/m/Y'),
                    $this->at($timeLabel),
                    '' !== $groups ? sprintf(' de %s', $groups) : '',
                    $grouping->getRoomName(),
                ),
            );
            ++$sent;
        }

        return $sent;
    }

    /**
     * Avisa a cada profesor de guardia implicado de que los grupos que cubre van juntos en un aula. Un
     * solo aviso por persona con TODOS sus grupos de esa agrupación, no uno por grupo: lo que necesita
     * saber es a dónde ir y con quién se encuentra.
     *
     * @param GuardiaGrouping    $grouping  la agrupación
     * @param list<GuardiaCover> $covers    las líneas del parte agrupadas
     * @param string|null        $timeLabel la hora en formato "08:25–09:20", si se conoce
     *
     * @return int a cuántas personas se avisó
     */
    public function notifyGrouped(GuardiaGrouping $grouping, array $covers, ?string $timeLabel): int
    {
        // Los grupos se LISTAN, no se cuentan: una línea del parte puede llevar varios grupos (actividad
        // multigrupo en el salón de actos, cuyo snapshot es «E2B, E2C»), así que un contador de líneas
        // diría «2 grupos» delante de una lista de tres nombres. La lista es la verdad.
        $allGroups = array_values(array_filter(array_map(static fn (GuardiaCover $c): ?string => $c->getGroupName(), $covers)));

        /** @var array<int, array{teacher: User, groups: list<string>}> $byTeacher */
        $byTeacher = [];
        foreach ($covers as $cover) {
            $teacher = $cover->getAssignedGuardia();
            if (!$teacher instanceof User || null === $teacher->getId()) {
                continue;
            }
            $byTeacher[$teacher->getId()] ??= ['teacher' => $teacher, 'groups' => []];
            if (null !== $cover->getGroupName()) {
                $byTeacher[$teacher->getId()]['groups'][] = $cover->getGroupName();
            }
        }

        foreach ($byTeacher as $row) {
            $this->dispatcher->dispatch(
                $row['teacher'],
                'guardia.grouped',
                sprintf('Guardia agrupada en %s: %s', $grouping->getRoomName(), $grouping->getDate()->format('d/m/Y')),
                sprintf(
                    'El %s%s tu guardia se hace en %s, con estos grupos juntos: %s.%s',
                    $grouping->getDate()->format('d/m/Y'),
                    $this->at($timeLabel),
                    $grouping->getRoomName(),
                    implode(', ', $allGroups),
                    $this->noteSuffix($grouping),
                ),
            );
        }

        return \count($byTeacher);
    }

    /**
     * Agrupa las clases desplazadas por docente, saltando a los ausentes: una persona con dos grupos en
     * la misma aula y hora recibe un aviso, no dos.
     *
     * @param list<ScheduleEntry> $displaced las clases que estaban en el aula
     * @param list<int>           $absentIds ids de docentes ausentes ese tramo
     *
     * @return list<array{0: User, 1: string}> pares (docente, grupos separados por coma)
     */
    private function byTeacher(array $displaced, array $absentIds): array
    {
        /** @var array<int, array{teacher: User, groups: list<string>}> $rows */
        $rows = [];
        foreach ($displaced as $entry) {
            $teacherId = $entry->getTeacher()->getId();
            if (null === $teacherId || \in_array($teacherId, $absentIds, true)) {
                continue;
            }
            $rows[$teacherId] ??= ['teacher' => $entry->getTeacher(), 'groups' => []];
            if (null !== $entry->getGroupName()) {
                $rows[$teacherId]['groups'][] = $entry->getGroupName();
            }
        }

        return array_values(array_map(
            static fn (array $row): array => [$row['teacher'], implode(', ', array_values(array_unique($row['groups'])))],
            $rows,
        ));
    }

    /**
     * La hora como complemento (" a las 08:25–09:20"), o cadena vacía si el curso no tiene horario
     * importado y por tanto no se conoce.
     *
     * @param string|null $timeLabel la hora ya formateada, o null
     *
     * @return string el complemento a insertar
     */
    private function at(?string $timeLabel): string
    {
        return null !== $timeLabel ? sprintf(' a las %s', $timeLabel) : '';
    }

    /**
     * La nota del coordinador como sufijo del cuerpo del aviso, o cadena vacía si no hay nota.
     *
     * @param GuardiaGrouping $grouping la agrupación
     *
     * @return string el sufijo a añadir
     */
    private function noteSuffix(GuardiaGrouping $grouping): string
    {
        return null !== $grouping->getNote() ? sprintf(' %s', $grouping->getNote()) : '';
    }
}
