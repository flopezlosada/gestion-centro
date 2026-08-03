<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Department;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\User;
use App\Enum\DeliverableRequirement;
use App\Enum\TaskType;

/**
 * El catálogo de tareas del centro (el CSV que salió de las actas de dirección) y cómo se convierte en
 * tareas de un curso: leer las filas válidas, decidir de quién es cada una y ponerle fecha.
 *
 * Vive aparte de los comandos porque tiene DOS consumidores que no pueden divergir:
 * {@see \App\Command\ImportCentreTasksCommand}, que siembra el catálogo real en un entorno de verdad, y
 * {@see \App\Command\SeedDemoCommand}, que lo usa para inventar actividad de demostración encima. Antes
 * esto vivía entero dentro del seeder de demo, que es exactamente por lo que en producción «las tareas
 * de centro se crean a mano»: la lógica útil estaba encerrada en un comando que se niega a correr en
 * prod y que además vacía `academic_year`.
 *
 * Lo que este servicio NO hace, a propósito: no persiste, no toca el estado del flujo de trabajo y no
 * inventa entregas. Devuelve {@see CentreTaskDraft} y quien llama decide qué hacer con ellos — así el
 * import puede dejarlas todas pendientes y la demo puede variarles el estado sin que ninguno de los dos
 * herede las decisiones del otro.
 */
final class CentreTaskCatalog
{
    /**
     * Palabras del título que delatan una tarea con ENTREGABLE (produce un documento). Heurística sobre
     * texto libre, no una columna del CSV: el catálogo no distingue, y una memoria o una programación
     * pide algo que enseñar mientras «revisar el tablón» no.
     */
    private const string DELIVERABLE_PATTERN = '/memoria|programaci|informe|calendario|publicar|presupuesto|\bpga\b|horario|\bacta|protocolo|proyecto|plan\b|documento|listado|cuadrante/iu';

    /** Columnas mínimas que ha de traer una fila para ser interpretable. */
    private const int MIN_COLUMNS = 7;

    public function __construct(private readonly SchoolCalendar $calendar)
    {
    }

    /**
     * Roles que el catálogo necesita para repartir responsabilidades. Se comprueba antes de importar
     * para fallar con un mensaje claro en vez de un error de índice a mitad del recorrido.
     *
     * @return list<string> los códigos de rol requeridos
     */
    public static function requiredRoleCodes(): array
    {
        return ['direction', 'head_of_studies', 'secretary', 'head_dept', 'tutor', 'teacher'];
    }

    /**
     * Lee las filas VÁLIDAS del catálogo: descarta las marcadas «Dudoso» y las que dirección marcó
     * como no válidas.
     *
     * @param string $csvPath ruta del CSV del catálogo
     *
     * @return list<array{id: string, bloque: string, tarea: string, responsable: string, cuando: string, tipo: string, origen: string}> las filas válidas
     *
     * @throws \RuntimeException si el fichero no se puede abrir
     */
    public function read(string $csvPath): array
    {
        $handle = @fopen($csvPath, 'r');
        if (false === $handle) {
            // Antes esto devolvía [] en silencio, lo que en un import de producción significa "he
            // sembrado cero tareas y no te lo he dicho".
            throw new \RuntimeException(sprintf('No se puede leer el catálogo de tareas en "%s".', $csvPath));
        }

        try {
            fgetcsv($handle); // cabecera
            $rows = [];
            while (false !== ($line = fgetcsv($handle))) {
                if (\count($line) < self::MIN_COLUMNS || '' === trim((string) $line[2])) {
                    continue;
                }
                $tipo = trim((string) $line[5]);
                $valida = mb_strtoupper(trim((string) ($line[7] ?? '')));
                if ('Dudoso' === $tipo || 'NO' === $valida) {
                    continue;
                }
                $rows[] = [
                    'id' => trim((string) $line[0]),
                    'bloque' => trim((string) $line[1]),
                    'tarea' => trim((string) $line[2]),
                    'responsable' => trim((string) $line[3]),
                    'cuando' => trim((string) $line[4]),
                    'tipo' => $tipo,
                    'origen' => trim((string) $line[6]),
                ];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Convierte las filas en tareas del curso dado, sin persistirlas.
     *
     * @param list<array{id: string, bloque: string, tarea: string, responsable: string, cuando: string, tipo: string, origen: string}> $rows        las filas válidas del catálogo
     * @param AcademicYear                                                                                                             $year        el curso al que se estampan
     * @param array<string, Role>                                                                                                      $roles       el catálogo de roles por código
     * @param list<Department>                                                                                                         $departments los departamentos reales
     *
     * @return list<CentreTaskDraft> una tarea por fila, en el orden del catálogo
     */
    public function plan(array $rows, AcademicYear $year, array $roles, array $departments): array
    {
        $total = \count($rows);
        $schoolYear = $year->getSchoolYear();

        $drafts = [];
        foreach ($rows as $index => $row) {
            [$role, $department] = $this->resolveResponsibility($row['responsable'], $roles, $departments, $index);
            $responsibility = new TaskResponsibility($role, $department);
            $holders = $responsibility->holders();

            $type = preg_match(self::DELIVERABLE_PATTERN, $row['tarea']) ? TaskType::WITH_DELIVERABLE : TaskType::SIMPLE;
            [$dueDate, $derived] = $this->dueDateFor($row['bloque'], $row['cuando'], $index, $total, $year);

            $task = new Task(mb_substr($row['tarea'], 0, 200), $schoolYear, $dueDate, $type);
            $task->setResponsibility($responsibility)
                ->setUnit($department)
                ->setAssignedUser($holders[0] ?? null)
                ->setDeliverable(TaskType::WITH_DELIVERABLE === $type ? DeliverableRequirement::LINK : DeliverableRequirement::NONE)
                ->setDescription($this->describe($row));

            $drafts[] = new CentreTaskDraft($row['id'], $task, $responsibility, $derived);
        }

        return $drafts;
    }

    /**
     * Traduce la celda «Responsable» (texto libre) a una responsabilidad: un rol y, para los roles por
     * departamento, un departamento que de verdad tenga titular (rotando por índice para repartir).
     * Cae en Jefatura de Estudios, que es quien coordina, cuando la celda no nombra un rol claro.
     *
     * @param string              $responsable el texto de la celda
     * @param array<string, Role> $roles       el catálogo de roles por código
     * @param list<Department>    $departments los departamentos reales
     * @param int                 $index       el índice de fila, para rotar el departamento elegido
     *
     * @return array{0: Role, 1: Department|null} el rol y su departamento (null para roles de centro)
     */
    private function resolveResponsibility(string $responsable, array $roles, array $departments, int $index): array
    {
        $text = $this->fold($responsable);

        if (str_contains($text, 'direccion') || str_contains($text, 'directiv')) {
            return [$roles['direction'], null];
        }
        if (str_contains($text, 'secretar')) {
            return [$roles['secretary'], null];
        }
        // Jefatura de estudios ANTES de las ramas de departamento: «Jefatura de Estudios /
        // departamentos» es de jefatura, no una tarea de jefes de departamento.
        if (str_contains($text, 'jefatura de estudios') || str_contains($text, 'jefe de estudios') || str_contains($text, 'jefa de estudios')) {
            return [$roles['head_of_studies'], null];
        }
        if ((str_contains($text, 'jefe') && str_contains($text, 'departamento')) || str_contains($text, 'jefes') || str_contains($text, 'departamento')) {
            return [$roles['head_dept'], $this->deptFromText($text, $roles['head_dept'], $departments, $index)];
        }
        if (str_contains($text, 'tutor')) {
            return [$roles['tutor'], $this->deptWithHolder($roles['tutor'], $departments, $index)];
        }
        if (str_contains($text, 'orientacion')) {
            return [$roles['head_dept'], $this->deptByName('Orientación', $departments) ?? $this->deptWithHolder($roles['head_dept'], $departments, $index)];
        }
        if (str_contains($text, 'profesorado') || str_contains($text, 'claustro') || str_contains($text, 'materia')) {
            return [$roles['teacher'], $this->deptWithHolder($roles['teacher'], $departments, $index)];
        }

        // CCP, convivencia, extraescolares, coordinación… las coordina jefatura de estudios.
        return [$roles['head_of_studies'], null];
    }

    /**
     * Elige el departamento de una tarea de jefatura de departamento: el que nombre el texto si se
     * reconoce, y si no uno que tenga titular, rotando por índice.
     *
     * @param string           $text        el texto normalizado de la celda
     * @param Role             $role        el rol por departamento
     * @param list<Department> $departments los departamentos reales
     * @param int              $index       el índice de fila, para rotar
     *
     * @return Department|null el departamento elegido, o null si ninguno tiene titular
     */
    private function deptFromText(string $text, Role $role, array $departments, int $index): ?Department
    {
        // Los fragmentos más específicos primero, para que «educación física» no se lo coma «física».
        $fragments = ['educacion fisica' => 'Educación Física', 'matemat' => 'Matemáticas', 'lengua' => 'Lengua', 'economia' => 'Economía', 'fisica' => 'Física', 'latin' => 'Latín', 'musica' => 'Música', 'ingl' => 'Ingles', 'biolog' => 'Biología', 'tecnolog' => 'Tecnología', 'geografia' => 'Geografía'];
        foreach ($fragments as $needle => $name) {
            if (str_contains($text, $needle)) {
                $match = $this->deptByName($name, $departments);
                if (null !== $match) {
                    return $match;
                }
            }
        }

        return $this->deptWithHolder($role, $departments, $index);
    }

    /**
     * El primer departamento cuyo nombre contiene el fragmento dado, sin distinguir acentos.
     *
     * @param string           $fragment    el fragmento a buscar
     * @param list<Department> $departments los departamentos reales
     *
     * @return Department|null la coincidencia, o null
     */
    private function deptByName(string $fragment, array $departments): ?Department
    {
        $needle = $this->fold($fragment);
        foreach ($departments as $department) {
            if (str_contains($this->fold($department->getName()), $needle)) {
                return $department;
            }
        }

        return null;
    }

    /**
     * Un departamento que hoy tenga titular activo del rol dado, rotando por índice para repartir.
     * Si ninguno lo tiene, cualquiera.
     *
     * @param Role             $role        el rol que necesita titular
     * @param list<Department> $departments los departamentos reales
     * @param int              $index       el índice de fila, para rotar
     *
     * @return Department|null el departamento elegido, o null si no hay ninguno
     */
    private function deptWithHolder(Role $role, array $departments, int $index): ?Department
    {
        $withHolder = array_values(array_filter(
            $departments,
            static fn (Department $d): bool => [] !== array_filter(
                $role->getUsers()->toArray(),
                static fn (User $u): bool => $u->isActive() && $u->getUnit() === $d,
            ),
        ));
        $pool = [] !== $withHolder ? $withHolder : $departments;

        return [] === $pool ? null : ($pool[$index % \count($pool)] ?? null);
    }

    /**
     * La fecha límite de una fila dentro del curso, y si sale del catálogo o es un relleno.
     *
     * Ancla de verdad tres formas de decir CUÁNDO que el centro usa de continuo: cerca del comienzo
     * para «inicio de curso», cerca del final para «fin de curso», y al cierre de un trimestre para
     * «cada evaluación». Cualquier otra cosa («a lo largo del curso», «cuando proceda») no se puede
     * anclar, así que se reparte por el año y se marca como NO deducida: de esas fechas salen
     * recordatorios, y quien importe tiene derecho a saber cuáles se ha inventado la máquina.
     *
     * El resultado se ajusta a un día lectivo y se recorta a los límites del curso.
     *
     * @param string       $bloque el bloque del catálogo
     * @param string       $cuando el texto libre de cuándo
     * @param int          $index  el índice de fila
     * @param int          $total  el número total de filas (para el reparto)
     * @param AcademicYear $year   la estructura del curso
     *
     * @return array{0: \DateTimeImmutable, 1: bool} la fecha (en día lectivo, dentro del curso) y si se dedujo
     */
    private function dueDateFor(string $bloque, string $cuando, int $index, int $total, AcademicYear $year): array
    {
        $start = $year->getYearStart();
        $end = $year->getYearEnd();
        $context = $this->fold($bloque.' '.$cuando);

        // null = el catálogo no dice nada anclable; se distingue del reparto de relleno de abajo.
        // Primero los hitos gruesos del BLOQUE, y solo si no dicen nada, lo que diga la celda «Cuándo»,
        // que es donde el centro es específico (trimestre, mes o día exacto).
        $anchored = match (true) {
            str_contains($context, 'inicio de curso'), str_contains($context, 'principio de curso'), str_contains($context, 'septiembre') => $start->modify('+'.(5 + $index % 10).' days'),
            str_contains($context, 'fin de curso'), str_contains($context, 'final de curso') => $end->modify('-'.(3 + $index % 10).' days'),
            str_contains($context, 'evaluacion') => $year->getTermEnd(1 + $index % 3)->modify('-'.($index % 4).' days'),
            default => $this->anchorFromTiming($cuando, $year),
        };
        $derived = null !== $anchored;

        $date = $this->calendar->onOrBeforeLectiveDay($anchored ?? $this->spreadAcrossYear($start, $end, $index, $total));
        if ($date < $start) {
            $date = $start;
        } elseif ($date > $end) {
            $date = $end;
        }

        return [$date, $derived];
    }

    /**
     * Ancla la celda «Cuándo» cuando dice algo concreto. Tres formas cubren la práctica totalidad del
     * catálogo real (medido sobre el CSV del centro: 83 de las 90 filas que antes caían al relleno):
     *
     *  - **«1er trimestre», «2º trimestre», «3er trimestre»** (57 filas): el plazo es el CIERRE de ese
     *    trimestre. Una tarea «del primer trimestre» hay que tenerla hecha cuando el trimestre acaba.
     *  - **día y mes** («17 de junio», «Antes del 23 de febrero», «Semana del 2-6 de marzo»): ese día.
     *    De los rangos se toma el PRIMER número, que es cuando empieza a hacer falta.
     *  - **solo el mes** («Enero», «Abril-mayo»): el último día de ese mes, que es lo que significa
     *    «en enero» dicho como plazo. De los rangos vale el primer mes por lo mismo.
     *
     * El año no se puede suponer: el curso va de septiembre a junio, así que septiembre–diciembre son
     * del año de inicio y enero–agosto del siguiente. Se deriva del propio curso, no del reloj.
     *
     * @param string       $cuando el texto libre de la celda «Cuándo»
     * @param AcademicYear $year   la estructura del curso
     *
     * @return \DateTimeImmutable|null la fecha que dice el catálogo, o null si no dice ninguna
     */
    private function anchorFromTiming(string $cuando, AcademicYear $year): ?\DateTimeImmutable
    {
        $text = $this->fold($cuando);

        if (str_contains($text, 'trimestre')) {
            $term = match (true) {
                str_contains($text, '1'), str_contains($text, 'primer') => 1,
                str_contains($text, '2'), str_contains($text, 'segundo') => 2,
                str_contains($text, '3'), str_contains($text, 'tercer') => 3,
                default => null,
            };
            if (null !== $term) {
                return $year->getTermEnd($term);
            }
        }

        $months = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];

        // Día explícito: «5 de mayo», «Antes del 18-22 de mayo» (se queda con el 18).
        if (1 === preg_match('/(\d{1,2})\s*(?:-\s*\d{1,2}\s*)?de\s+([a-z]+)/u', $text, $match)
            && isset($months[$match[2]])) {
            $month = $months[$match[2]];

            return $this->inCourseYear($year, $month, min(31, max(1, (int) $match[1])));
        }

        // Solo el mes: el plazo es el final de ese mes.
        foreach ($months as $name => $month) {
            if (str_contains($text, $name)) {
                return $this->inCourseYear($year, $month, 1)->modify('last day of this month');
            }
        }

        return null;
    }

    /**
     * Una fecha de mes y día colocada en el año natural que le toca dentro del curso: septiembre a
     * diciembre caen en el año de inicio y enero a agosto en el siguiente.
     *
     * @param AcademicYear $year  la estructura del curso
     * @param int          $month el mes, 1-12
     * @param int          $day   el día del mes
     *
     * @return \DateTimeImmutable la fecha, saneada si el día no existe en ese mes
     */
    private function inCourseYear(AcademicYear $year, int $month, int $day): \DateTimeImmutable
    {
        $startYear = (int) $year->getYearStart()->format('Y');
        $calendarYear = $month >= 9 ? $startYear : $startYear + 1;

        // Con la hora a cero explícita: sin ella sería la del reloj y dos ejecuciones darían instantes
        // distintos. Y el día se recorta al último del mes (un «31 de febrero» del catálogo desbordaría
        // a marzo en silencio).
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $calendarYear, $month));

        return $first->modify('+'.(min($day, (int) $first->format('t')) - 1).' days');
    }

    /**
     * Reparto de relleno para las filas cuyo «cuándo» no se puede anclar: la fila N de T, colocada
     * proporcionalmente entre el comienzo y el final del curso. NO es una fecha del centro.
     *
     * @param \DateTimeImmutable $start comienzo del curso
     * @param \DateTimeImmutable $end   final del curso
     * @param int                $index el índice de fila
     * @param int                $total el número total de filas
     *
     * @return \DateTimeImmutable la fecha repartida
     */
    private function spreadAcrossYear(\DateTimeImmutable $start, \DateTimeImmutable $end, int $index, int $total): \DateTimeImmutable
    {
        return $start->modify('+'.(int) floor(($index / max(1, $total - 1)) * (int) $start->diff($end)->days).' days');
    }

    /**
     * Una descripción corta para la tarea, con su bloque, su cuándo y el acta de la que sale.
     *
     * @param array{bloque: string, cuando: string, origen: string} $row la fila del catálogo
     *
     * @return string|null la descripción, o null si no hay nada que decir
     */
    private function describe(array $row): ?string
    {
        $parts = array_filter([
            '' !== $row['bloque'] ? $row['bloque'] : null,
            '' !== $row['cuando'] ? 'Cuándo: '.$row['cuando'] : null,
            '' !== $row['origen'] ? 'Origen: '.$row['origen'] : null,
        ]);

        return [] !== $parts ? implode(' · ', $parts) : null;
    }

    /**
     * Pasa a minúsculas y quita los acentos, para comparar texto libre sin depender de la tilde.
     *
     * @param string $text el texto
     *
     * @return string el texto normalizado
     */
    private function fold(string $text): string
    {
        return strtr(mb_strtolower($text, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    }
}
