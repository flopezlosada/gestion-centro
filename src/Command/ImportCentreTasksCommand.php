<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AcademicYear;
use App\Entity\Department;
use App\Entity\Role;
use App\Repository\AcademicYearRepository;
use App\Repository\TaskRepository;
use App\Service\CentreTaskCatalog;
use App\Service\CentreTaskDraft;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Siembra el catálogo de tareas del centro en el curso indicado, y NADA MÁS.
 *
 * Existe porque hasta ahora ese catálogo solo se podía cargar con {@see SeedDemoCommand}, que además de
 * inventar actividad **vacía `academic_year`** y se llevaría por delante el horario, el marco horario y
 * el cuadrante recién montados. O sea: en un entorno real el paso «meter las tareas del centro» era
 * literalmente imposible y había que teclearlas a mano. Este comando toca solo `task` y
 * `task_responsibility`.
 *
 * **Idempotente por (curso, título).** Reejecutarlo no duplica: una fila cuyo título ya existe en ese
 * curso se salta, y ni le cambia la fecha ni le toca el estado — quien ya movió una tarea a mano manda
 * sobre el CSV. Lo que sí hace es crear las que falten, así que también sirve para completar el curso
 * después de corregir el catálogo.
 *
 * El CSV se pasa como argumento a propósito: NO está en el repositorio (lleva el trabajo interno de
 * dirección) y hay que subirlo al servidor. Con `--dry-run` no escribe nada y enseña lo que haría.
 *
 * Aviso que el comando da en voz alta y conviene entender: el catálogo dice CUÁNDO en texto libre, y no
 * todas las formas se pueden anclar al calendario del curso. Lo que no se puede recibe una fecha
 * REPARTIDA por el año que no la dice el centro, y de las fechas salen recordatorios por correo y por
 * móvil. El resumen separa unas de otras para que dirección repase las inventadas
 * → ver {@see CentreTaskCatalog}.
 *
 * **Deuda, ahora ya desbloqueada:** lo correcto sería sembrar {@see \App\Entity\TaskTemplate} y dejar
 * que la generación anual ponga las fechas — el CSV es literalmente un catálogo de tareas «Recurrente»,
 * y así el centro no volvería a importar nada el curso que viene. Cuando esto se escribió no se podía,
 * porque `Task::fromTemplate()` rellenaba el campo legacy `assignedRole` y dejaba las tareas generadas a
 * medias; el refactor de responsabilidad ya cerró ese agujero y hoy escribe `TaskResponsibility`. O sea
 * que el impedimento desapareció y esto es un cambio pendiente, no un imposible.
 */
#[AsCommand(
    name: 'app:import-centre-tasks',
    description: 'Siembra el catálogo de tareas del centro (CSV) en un curso, sin tocar nada más. Idempotente.',
)]
final class ImportCentreTasksCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CentreTaskCatalog $catalog,
        private readonly AcademicYearRepository $years,
        private readonly TaskRepository $tasks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('csv', InputArgument::REQUIRED, 'Ruta del catálogo (catalogo-tareas-para-direccion.csv). No está en git: súbelo al servidor.')
            ->addOption('curso', null, InputOption::VALUE_REQUIRED, 'Curso destino en formato "2026-2027". Por defecto, el curso en marcha.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'No escribe nada: enseña qué se crearía y qué se saltaría.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $year = $this->resolveYear($input, $io);
        if (null === $year) {
            return Command::FAILURE;
        }

        $roles = $this->rolesByCode();
        $missing = array_values(array_diff(CentreTaskCatalog::requiredRoleCodes(), array_keys($roles)));
        if ([] !== $missing) {
            $io->error(sprintf('Faltan roles en el catálogo del centro: %s.', implode(', ', $missing)));
            // Ojo con la instrucción: `app:import-roster` NO crea el catálogo entero. Solo da de alta los
            // cargos que aparecen en el listado del claustro (dirección, jefaturas, secretaría, tutorías y
            // docente); `head_dept` lo dice el propio comando de roster («los jefes de departamento no
            // vienen en el origen»), y `tic` y `guardias` tampoco están ahí. Mandar a la gente a
            // reimportar el roster para arreglar esto la haría dar vueltas.
            $io->note('Los cargos del listado del claustro los crea "app:import-roster", pero jefatura de departamento, TIC y coordinación de guardias NO vienen en ese origen: créalos en /admin/roles.');

            return Command::FAILURE;
        }

        /** @var list<Department> $departments */
        $departments = $this->em->getRepository(Department::class)->findAll();
        if ([] === $departments) {
            $io->error('No hay departamentos cargados: las tareas de departamento no tendrían a quién asignarse.');
            $io->note('Ejecuta "app:import-roster" antes.');

            return Command::FAILURE;
        }

        try {
            $rows = $this->catalog->read((string) $input->getArgument('csv'));
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if ([] === $rows) {
            // FAILURE y no SUCCESS: el caso típico es un CSV reexportado desde Excel en español, que sale
            // separado por `;` — `fgetcsv` devuelve una sola columna por línea, se descartan todas y el
            // comando habría dicho «bien» tras sembrar cero tareas. En un script de despliegue eso es
            // justo el fallo silencioso que este comando existe para quitar.
            $io->error('El catálogo no tiene ninguna fila válida.');
            $io->note('Comprueba que es el fichero correcto y que está separado por COMAS: un CSV exportado desde Excel en español suele venir con punto y coma.');

            return Command::FAILURE;
        }

        $drafts = $this->catalog->plan($rows, $year, $roles, $departments);
        $existing = $this->titlesAlreadyIn($year->getSchoolYear());

        $created = [];
        $skipped = 0;
        foreach ($drafts as $draft) {
            $key = $this->titleKey($draft->task->getTitle());
            if (isset($existing[$key])) {
                ++$skipped;
                continue;
            }
            // Marca también dentro de la misma pasada: el catálogo puede repetir un título y dos filas
            // idénticas no son dos tareas.
            $existing[$key] = true;
            $created[] = $draft;

            if (!$dryRun) {
                // La responsabilidad viaja con la tarea: `Task::$responsibility` es OneToOne con
                // cascade persist.
                $this->em->persist($draft->task);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $this->report($io, $year, $created, $skipped, $dryRun);

        return Command::SUCCESS;
    }

    /**
     * El curso destino: el que se pide por opción, o el que está en marcha. Falla con instrucciones si
     * no está definido, porque sin las fechas de los trimestres no hay dónde colocar los plazos.
     *
     * @param InputInterface $input la entrada del comando
     * @param SymfonyStyle   $io    la salida, para explicar el fallo
     *
     * @return AcademicYear|null el curso, o null si no se puede continuar
     */
    private function resolveYear(InputInterface $input, SymfonyStyle $io): ?AcademicYear
    {
        $requested = $input->getOption('curso');
        // «Ahora» a secas: la zona del centro ya es la de PHP desde el arranque ({@see \App\Kernel}), y
        // aquí importa de verdad — cerca del 1 de septiembre «en qué curso estamos» cambia de respuesta
        // según la zona del proceso.
        $schoolYear = null !== $requested
            ? (string) $requested
            : SchoolYear::current(new \DateTimeImmutable('now'));

        $year = $this->years->findBySchoolYear($schoolYear);
        if (null === $year) {
            $io->error(sprintf('El curso %s no está definido.', $schoolYear));
            $io->note('Créalo en /admin/cursos con las fechas de los tres trimestres: de ahí salen los plazos.');

            return null;
        }

        return $year;
    }

    /**
     * Los títulos de tareas que ese curso ya tiene, normalizados, como conjunto de búsqueda.
     *
     * El título es la clave de idempotencia porque es lo único estable que comparten el CSV y la base de
     * datos: el identificador del catálogo («A1-01») no se guarda en `task`, y añadir una columna para
     * ello sería tocar el modelo de tareas que se está rehaciendo por otro lado. Se compara sin
     * distinguir mayúsculas ni espacios de sobra, que es como se cuelan los duplicados de verdad.
     *
     * @param string $schoolYear el curso en formato "YYYY-YYYY"
     *
     * @return array<string, true> conjunto de títulos normalizados
     */
    private function titlesAlreadyIn(string $schoolYear): array
    {
        $keys = [];
        foreach ($this->tasks->findBySchoolYear($schoolYear) as $task) {
            $keys[$this->titleKey($task->getTitle())] = true;
        }

        return $keys;
    }

    /**
     * Forma normalizada de un título, para comparar sin depender de mayúsculas ni espacios.
     *
     * @param string $title el título
     *
     * @return string la clave de comparación
     */
    private function titleKey(string $title): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($title)) ?? $title, 'UTF-8');
    }

    /**
     * El catálogo de roles indexado por su código estable.
     *
     * @return array<string, Role> los roles por código
     */
    private function rolesByCode(): array
    {
        $byCode = [];
        foreach ($this->em->getRepository(Role::class)->findAll() as $role) {
            $byCode[$role->getCode()] = $role;
        }

        return $byCode;
    }

    /**
     * Resumen de la pasada, separando las tareas con fecha DEDUCIDA del catálogo de las que llevan una
     * fecha de relleno, que son las que dirección tiene que repasar.
     *
     * @param SymfonyStyle          $io      la salida
     * @param AcademicYear          $year    el curso sembrado
     * @param list<CentreTaskDraft> $created las tareas creadas (o que se crearían)
     * @param int                   $skipped cuántas ya existían
     * @param bool                  $dryRun  si no se ha escrito nada
     */
    private function report(SymfonyStyle $io, AcademicYear $year, array $created, int $skipped, bool $dryRun): void
    {
        $invented = array_values(array_filter($created, static fn (CentreTaskDraft $d): bool => !$d->deadlineDerived));

        $io->table(['Resultado', 'Tareas'], [
            [$dryRun ? 'Se crearían' : 'Creadas', (string) \count($created)],
            ['Ya existían (se saltan)', (string) $skipped],
            ['Con fecha deducida del catálogo', (string) (\count($created) - \count($invented))],
            ['Con fecha de RELLENO (repasar)', (string) \count($invented)],
        ]);

        $message = sprintf('Catálogo del centro sembrado en el curso %s.', $year->getSchoolYear());
        $dryRun ? $io->note($message.' (dry-run: no se ha escrito nada)') : $io->success($message);

        if ([] === $invented) {
            return;
        }

        $io->warning(sprintf(
            '%d tareas llevan una fecha límite REPARTIDA por el curso, no una fecha del centro: el catálogo '
            .'no dice cuándo son de una forma que se pueda anclar. De esas fechas saldrán recordatorios, '
            .'así que conviene repasarlas en /tareas.',
            \count($invented),
        ));
        $io->listing(array_map(
            static fn (CentreTaskDraft $d): string => sprintf(
                '%s · %s → %s',
                $d->catalogId,
                $d->task->getTitle(),
                $d->task->getDueDate()->format('d/m/Y'),
            ),
            \array_slice($invented, 0, 15),
        ));
        if (\count($invented) > 15) {
            $io->text(sprintf('… y %d más.', \count($invented) - 15));
        }
    }
}
