<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\Department;
use App\Entity\EventCategory;
use App\Entity\GuardiaCover;
use App\Entity\NonLectiveDay;
use App\Entity\Notification;
use App\Entity\PersonalEvent;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\User;
use App\Enum\CategoryColor;
use App\Enum\TaskType;
use App\Service\SchoolCalendar;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * DEV/TEST seeder: layers an invented activity layer (course calendar, centre tasks from the real
 * catalog, personal agendas and inbox notices) ON TOP of the real staff already loaded by
 * {@see ImportRosterCommand}. Unlike {@see \App\DataFixtures\DemoFixtures} — which builds a
 * self-contained synthetic org and PURGES the whole database — this reuses the real people and
 * departments and only ever touches the activity tables, so the realistic instance is
 * `--group=golden` + `app:import-roster` + `app:seed-demo`.
 *
 * Idempotent: it clears just the activity tables (tasks, personal events, notifications, calendar)
 * and regenerates, never touching app_user / org_unit / role / task_template. Refused in prod.
 *
 * Because the source PDF carries no heads of department, this also PROMOTES one real teacher per
 * department to `head_dept` (invented, so the department-scoped catalog tasks resolve to a person and
 * the chain of command looks complete). That is the only real-data change it makes and it is
 * idempotent (the role is added once per holder).
 */
#[AsCommand(name: 'app:seed-demo', description: 'DEV: genera actividad inventada (tareas de centro, agenda, avisos, calendario) sobre el claustro real')]
final class SeedDemoCommand extends Command
{
    /** Activity tables owned by this seeder, in FK-safe deletion order (children before parents). */
    private const array ACTIVITY_TABLES = [
        'notification',
        'task',
        'task_responsibility',
        'personal_event',
        'guardia_cover',
        'non_lective_day',
        'academic_year',
        'event_category',
    ];

    /** Fixed RNG seed so the invented guardia rota is the same on every run (reproducible demo). */
    private const int GUARDIA_SEED = 20260701;

    /** Invented absences to generate per term, spread across weekdays, slots and teachers. */
    private const int GUARDIAS_PER_TERM = 45;

    /** Words in a catalog title that mark it as a deliverable task (produces a document). */
    private const string DELIVERABLE_PATTERN = '/memoria|programaci|informe|calendario|publicar|presupuesto|\bpga\b|horario|\bacta|protocolo|proyecto|plan\b|documento|listado|cuadrante/iu';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SchoolCalendar $calendar,
        #[Autowire('%kernel.environment%')] private readonly string $env,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Permite ejecutarlo aunque el entorno sea prod. Pensado para un staging que corre como prod: '
            .'siembra datos inventados a propósito. NO usar en un prod real con datos de verdad.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // El seed genera datos inventados: se niega en prod para no arruinar datos reales por accidente.
        // En un staging que corre con APP_ENV=prod, --force lo habilita de forma deliberada (y ruidosa).
        if ('prod' === $this->env && !$input->getOption('force')) {
            $io->error('app:seed-demo genera datos inventados y no puede ejecutarse en producción. Usa --force solo si este entorno es un staging.');

            return Command::FAILURE;
        }
        if ('prod' === $this->env) {
            $io->warning('Ejecutando en entorno PROD por --force: se regenerará la actividad inventada. Asegúrate de que es un staging, no un prod real.');
        }

        /** @var list<User> $users */
        $users = $this->em->getRepository(User::class)->findAll();
        /** @var list<Department> $departments */
        $departments = $this->em->getRepository(Department::class)->findAll();
        if ([] === $users || [] === $departments) {
            $io->error('No hay claustro cargado. Ejecuta primero:');
            $io->listing([
                'php bin/console doctrine:fixtures:load --group=golden --no-interaction',
                'php bin/console app:import-roster fixtures/real/roster.csv',
            ]);

            return Command::FAILURE;
        }

        $roles = $this->rolesByCode();
        foreach (['direction', 'head_of_studies', 'secretary', 'head_dept', 'tutor', 'teacher'] as $code) {
            if (!isset($roles[$code])) {
                $io->error(sprintf('Falta el rol golden "%s". Carga la golden antes de sembrar.', $code));

                return Command::FAILURE;
            }
        }

        $this->clearActivity();

        $courses = $this->seedCalendar();
        $academicYear = $courses[array_key_last($courses)]; // el curso actual: sobre él van tareas y agenda
        $heads = $this->inventDepartmentHeads($departments, $roles['head_dept']);
        $categories = $this->seedCategories();
        $director = $this->firstHolder($roles['direction']);

        $centre = $this->seedCentreTasks($academicYear, $roles, $departments, $director);
        $agenda = $this->seedPersonalAgenda($users, $categories, $academicYear->getSchoolYear(), $director);
        $notifications = $this->seedNotifications($agenda);
        // Guardias en TODOS los cursos sembrados, para poder comparar un año con otro en las estadísticas.
        $guardias = array_sum(array_map(fn (AcademicYear $ay): int => $this->seedGuardias($users, $ay), $courses));

        $this->em->flush();

        $io->success(sprintf('Actividad inventada generada sobre %d docentes y %d departamentos reales (curso %s).', \count($users), \count($departments), $academicYear->getSchoolYear()));
        $io->table(['Elemento', 'Creado'], [
            ['Jefes de departamento (inventados)', (string) \count($heads)],
            ['Categorías de agenda', (string) \count($categories)],
            ['Tareas de centro (catálogo)', (string) $centre],
            ['Eventos de agenda personal', (string) $agenda['events']],
            ['Tareas personales', (string) \count($agenda['tasks'])],
            ['Notificaciones', (string) $notifications],
            ['Guardias (ausencias, todos los cursos)', (string) $guardias],
        ]);
        $io->note('Los jefes de departamento son INVENTADOS (un docente por departamento) para que las tareas de departamento tengan titular. Reimporta el roster y vuelve a sembrar para regenerar.');

        return Command::SUCCESS;
    }

    /**
     * Wipes the activity tables this seeder owns, leaving the real backbone (people, departments,
     * roles, templates) untouched. Raw DELETEs in FK-safe order avoid ORM cascade quirks.
     */
    private function clearActivity(): void
    {
        $connection = $this->em->getConnection();
        foreach (self::ACTIVITY_TABLES as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }

    /**
     * The role catalog indexed by its stable code.
     *
     * @return array<string, Role> roles keyed by code
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
     * Seeds the course structure (three terms each) for the current school year AND the previous one —
     * two courses so the statistics can compare a year against another — plus a handful of real Comunidad
     * de Madrid non-teaching days of the current course that fall on a teaching weekday (weekends are
     * non-teaching on their own).
     *
     * @return list<AcademicYear> the persisted courses, oldest first (last one is the current course)
     */
    private function seedCalendar(): array
    {
        $current = SchoolYear::current(new \DateTimeImmutable());
        $previous = SchoolYear::previous($current);
        $courses = [$this->buildAcademicYear($previous), $this->buildAcademicYear($current)];

        $start = (int) substr($current, 0, 4);
        $holidays = [
            [sprintf('%d-10-31', $start), 'Día no lectivo (libre disposición)'],
            [sprintf('%d-12-08', $start), 'Inmaculada Concepción'],
            [sprintf('%d-01-06', $start + 1), 'Reyes'],
            [sprintf('%d-02-13', $start + 1), 'Día del Docente'],
            [sprintf('%d-05-01', $start + 1), 'Día del Trabajo'],
            [sprintf('%d-05-15', $start + 1), 'San Isidro'],
        ];
        foreach ($holidays as [$date, $description]) {
            $this->em->persist((new NonLectiveDay())->setDate(new \DateTimeImmutable($date))->setDescription($description));
        }

        return $courses;
    }

    /**
     * Builds and persists one course structure (three terms) for a school year, with the centre's usual
     * term dates anchored on its start year.
     *
     * @param string $schoolYear the school year in "YYYY-YYYY" form
     *
     * @return AcademicYear the persisted course
     */
    private function buildAcademicYear(string $schoolYear): AcademicYear
    {
        $start = (int) substr($schoolYear, 0, 4);
        $academicYear = (new AcademicYear())
            ->setSchoolYear($schoolYear)
            ->setTerm1Start(new \DateTimeImmutable($start.'-09-08'))
            ->setTerm1End(new \DateTimeImmutable($start.'-12-19'))
            ->setTerm2Start(new \DateTimeImmutable(($start + 1).'-01-08'))
            ->setTerm2End(new \DateTimeImmutable(($start + 1).'-03-27'))
            ->setTerm3Start(new \DateTimeImmutable(($start + 1).'-04-07'))
            ->setTerm3End(new \DateTimeImmutable(($start + 1).'-06-19'));
        $this->em->persist($academicYear);

        return $academicYear;
    }

    /**
     * Promotes one real teacher per department to the head-of-department role, so the department-scoped
     * catalog tasks resolve to a concrete person. Prefers a member who does not already hold a
     * centre-wide leadership role; falls back to the first member. Idempotent (the role is added once).
     *
     * @param list<Department> $departments the real departments
     * @param Role             $headDept    the per-department head role
     *
     * @return array<string, User> the chosen head per department code
     */
    private function inventDepartmentHeads(array $departments, Role $headDept): array
    {
        $leadershipCodes = ['direction', 'head_of_studies', 'head_of_studies_deputy', 'secretary'];
        $heads = [];
        foreach ($departments as $department) {
            /** @var list<User> $members */
            $members = $this->em->getRepository(User::class)->findBy(['unit' => $department, 'active' => true]);
            if ([] === $members) {
                continue;
            }

            $plain = array_filter(
                $members,
                static fn (User $u): bool => !array_reduce($leadershipCodes, static fn (bool $carry, string $code): bool => $carry || $u->holdsRoleCode($code), false),
            );
            $head = ($plain[array_key_first($plain)] ?? $members[0]);
            $head->addAssignedRole($headDept);
            $heads[$department->getCode()] = $head;
        }

        return $heads;
    }

    /**
     * Seeds the admin-managed colour categories used to tag personal agenda events.
     *
     * @return array<string, EventCategory> categories keyed by name
     */
    private function seedCategories(): array
    {
        $palette = [
            ['Tutoría', CategoryColor::BLUE],
            ['Reunión', CategoryColor::TEAL],
            ['Guardia', CategoryColor::AMBER],
            ['Formación', CategoryColor::GREEN],
            ['Evaluación', CategoryColor::RED],
            ['Personal', CategoryColor::SLATE],
        ];
        $categories = [];
        foreach ($palette as [$name, $color]) {
            $category = (new EventCategory())->setName($name)->setColor($color);
            $this->em->persist($category);
            $categories[$name] = $category;
        }

        return $categories;
    }

    /**
     * Instantiates the real centre-task catalog for the given course: one {@see Task} per valid row,
     * mapping the free-text "Responsable" to a role (and department for per-department roles), spreading
     * deadlines across the course calendar and varying workflow status. Rows flagged "Dudoso" or "NO"
     * are skipped.
     *
     * @param AcademicYear        $academicYear the course to stamp the tasks into
     * @param array<string, Role> $roles        the role catalog by code
     * @param list<Department>    $departments  the real departments (for per-department responsibilities)
     * @param User|null           $director     who to record as creator, if resolvable
     *
     * @return int the number of tasks created
     */
    private function seedCentreTasks(AcademicYear $academicYear, array $roles, array $departments, ?User $director): int
    {
        $rows = $this->readCatalog();
        $total = \count($rows);
        $year = $academicYear->getSchoolYear();

        foreach ($rows as $index => $row) {
            [$role, $department] = $this->resolveResponsibility($row['responsable'], $roles, $departments, $index);
            $responsibility = new TaskResponsibility($role, $department);
            $holders = $responsibility->holders();

            $type = preg_match(self::DELIVERABLE_PATTERN, $row['tarea']) ? TaskType::WITH_DELIVERABLE : TaskType::SIMPLE;
            $dueDate = $this->dueDateFor($row['bloque'], $row['cuando'], $index, $total, $academicYear);

            $task = new Task(mb_substr($row['tarea'], 0, 200), $year, $dueDate, $type);
            $task->setResponsibility($responsibility)
                ->setUnit($department)
                ->setAssignedUser($holders[0] ?? null)
                ->setRequiresDocument(TaskType::WITH_DELIVERABLE === $type)
                ->setCreatedBy($director)
                ->setDescription($this->describe($row));

            $status = $this->statusFor($index);
            $task->setStatus($status);
            // Invariante: una tarea con entregable ya entregada/finalizada tiene referencia (al entregar
            // se adjunta), así que los datos demo también la llevan.
            if (TaskType::WITH_DELIVERABLE === $type && \in_array($status, ['submitted', 'validated'], true)) {
                $task->setDeliverableReference('https://cloud.educa.madrid.org/'.$row['id']);
            }
            if ('validated' === $status) {
                $task->setCompletedBy($holders[0] ?? null);
            }

            $this->em->persist($task);
        }

        return $total;
    }

    /**
     * Reads the valid rows of the real centre-task catalog CSV, dropping the "Dudoso" ones and any
     * explicitly marked as not valid.
     *
     * @return list<array{id: string, bloque: string, tarea: string, responsable: string, cuando: string, tipo: string, origen: string}>
     */
    private function readCatalog(): array
    {
        $handle = fopen($this->projectDir.'/catalogo/catalogo-tareas-para-direccion.csv', 'r');
        if (false === $handle) {
            return [];
        }

        fgetcsv($handle); // header
        $rows = [];
        while (false !== ($line = fgetcsv($handle))) {
            if (\count($line) < 7 || '' === trim((string) $line[2])) {
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
        fclose($handle);

        return $rows;
    }

    /**
     * Maps a free-text "Responsable" cell to a responsibility: a role plus, for per-department roles, a
     * department that actually has a holder (rotated by row index for an even spread). Falls back to
     * Jefatura de Estudios, the operational coordinator, for coordination cells with no clear role.
     *
     * @param string              $responsable the raw responsible text
     * @param array<string, Role> $roles       the role catalog by code
     * @param list<Department>    $departments the real departments
     * @param int                 $index       the row index, used to rotate department choice
     *
     * @return array{0: Role, 1: Department|null} the role and its department (null for centre-wide roles)
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
        // Head of studies before the department branches: "Jefatura de Estudios / departamentos" is
        // jefatura's, not a head-of-department task.
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

        // CCP, convivencia, extraescolares, coordinación… → jefatura de estudios coordinates them.
        return [$roles['head_of_studies'], null];
    }

    /**
     * Picks the department for a per-department head task: a specific one named in the text if
     * recognised, otherwise a department that has a head, rotated by index.
     *
     * @param string           $text        the folded responsible text
     * @param Role             $role        the per-department role
     * @param list<Department> $departments the real departments
     * @param int              $index       the row index for rotation
     *
     * @return Department|null the chosen department, or null if none has a holder
     */
    private function deptFromText(string $text, Role $role, array $departments, int $index): ?Department
    {
        // Longer, more specific fragments first so "educación física" is not swallowed by "física".
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
     * The first department whose name contains the given fragment (accent-insensitive).
     *
     * @param string           $fragment    the name fragment to look for
     * @param list<Department> $departments the real departments
     *
     * @return Department|null the match, or null
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
     * A department that currently has an active holder of the given role, rotated by index for an even
     * spread across the demo. Falls back to any department when none matches.
     *
     * @param Role             $role        the role that needs a holder
     * @param list<Department> $departments the real departments
     * @param int              $index       the row index for rotation
     *
     * @return Department|null the chosen department, or null when there are none
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

        return $pool[$index % \count($pool)] ?? null;
    }

    /**
     * Computes a deadline for a catalog row inside the course: anchored near the start for "inicio de
     * curso" rows, near the end for "fin de curso", to a term end for "cada evaluación", and otherwise
     * spread evenly across the year by row index. The result is nudged onto a teaching day and clamped
     * to the course bounds.
     *
     * @param string        $bloque       the catalog block
     * @param string        $cuando       the free-text timing
     * @param int           $index        the row index
     * @param int           $total        the total number of rows (for the even spread)
     * @param AcademicYear  $academicYear the course structure
     *
     * @return \DateTimeImmutable the deadline, on a teaching day within the course
     */
    private function dueDateFor(string $bloque, string $cuando, int $index, int $total, AcademicYear $academicYear): \DateTimeImmutable
    {
        $start = $academicYear->getYearStart();
        $end = $academicYear->getYearEnd();
        $context = $this->fold($bloque.' '.$cuando);

        $date = match (true) {
            str_contains($context, 'inicio de curso'), str_contains($context, 'principio de curso'), str_contains($context, 'septiembre') => $start->modify('+'.(5 + $index % 10).' days'),
            str_contains($context, 'fin de curso'), str_contains($context, 'final de curso') => $end->modify('-'.(3 + $index % 10).' days'),
            str_contains($context, 'evaluacion') => $academicYear->getTermEnd(1 + $index % 3)->modify('-'.($index % 4).' days'),
            default => $start->modify('+'.(int) floor(($index / max(1, $total - 1)) * (int) $start->diff($end)->days).' days'),
        };

        $date = $this->calendar->onOrBeforeLectiveDay($date);
        if ($date < $start) {
            return $start;
        }

        return $date > $end ? $end : $date;
    }

    /**
     * Picks a workflow place for a demo task, distributed for variety and always a valid place of the
     * single task workflow: most are finalizadas (the course is largely done), the rest entregadas,
     * alguna cancelada y el resto pendientes.
     *
     * @param int $index the row index driving the distribution
     *
     * @return string a valid workflow place
     */
    private function statusFor(int $index): string
    {
        return match ($index % 10) {
            0, 1, 2, 3, 4, 5 => 'validated',
            6, 7 => 'submitted',
            8 => 'cancelled',
            default => 'pending',
        };
    }

    /**
     * Builds a short description for a catalog task from its block, timing and source act.
     *
     * @param array{bloque: string, cuando: string, origen: string} $row the catalog row
     *
     * @return string|null the description, or null when there is nothing to say
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
     * Seeds a lively personal layer for a spread of real people: agenda events around today (one-offs,
     * a recurring series, some done) plus a few ad-hoc personal tasks in the today/this-week/overdue
     * buckets, so the personal dashboard and calendar are populated now.
     *
     * @param list<User>                     $users      all real people
     * @param array<string, EventCategory>   $categories the seeded agenda categories by name
     * @param string                         $year       the current school year
     * @param User|null                      $director   who to record as creator of the personal tasks
     *
     * @return array{events: int, tasks: list<Task>} the event count and the personal tasks created
     */
    private function seedPersonalAgenda(array $users, array $categories, string $year, ?User $director): array
    {
        $owners = \array_slice($users, 0, 6);
        $today = new \DateTimeImmutable('today');
        $events = 0;
        $tasks = [];

        foreach ($owners as $position => $owner) {
            $spec = [
                ['Preparar la reunión de departamento', $today->modify('+2 days')->setTime(12, 30), $today->modify('+2 days')->setTime(13, 30), false, 'Reunión', false],
                ['Atención a familias', $today->modify('+1 day')->setTime(16, 0), $today->modify('+1 day')->setTime(17, 0), false, 'Tutoría', false],
                ['Recordar entrega de notas', $today, null, true, 'Evaluación', false],
                ['Revisión médica', $today->modify('-3 days')->setTime(9, 0), $today->modify('-3 days')->setTime(10, 0), false, 'Personal', true],
            ];
            foreach ($spec as [$title, $startAt, $endAt, $allDay, $categoryName, $done]) {
                $event = (new PersonalEvent($owner, $title, $startAt))
                    ->setEndAt($endAt)
                    ->setAllDay($allDay)
                    ->setCategory($categories[$categoryName] ?? null)
                    ->setDone($done);
                $this->em->persist($event);
                ++$events;
            }

            // A weekly recurring series (guardia) for the first two owners, sharing a series id.
            if ($position < 2) {
                $seriesId = bin2hex(random_bytes(16));
                $monday = $today->modify('monday this week')->setTime(11, 15);
                for ($week = 0; $week < 4; ++$week) {
                    $occurrence = $monday->modify('+'.$week.' weeks');
                    $this->em->persist(
                        (new PersonalEvent($owner, 'Guardia de pasillo', $occurrence))
                            ->setEndAt($occurrence->modify('+1 hour'))
                            ->setCategory($categories['Guardia'] ?? null)
                            ->setSeriesId($seriesId),
                    );
                    ++$events;
                }
            }
        }

        // A few ad-hoc personal tasks across the buckets for the first owner, so the worklist shows
        // overdue / today / upcoming / done at a glance.
        $owner = $owners[0] ?? null;
        if (null !== $owner) {
            $plan = [
                ['Subir el acta de la CCP', $today, false],
                ['Entregar la programación de aula', $today->modify('+3 days'), false],
                ['Revisar las propuestas de mejora', $today->modify('-2 days'), false],
                ['Actualizar el tablón del aula', $today->modify('-9 days'), true],
            ];
            $ownerRole = $owner->getAssignedRoles()->first();
            foreach ($plan as [$title, $due, $done]) {
                $dueDate = $this->calendar->onOrBeforeLectiveDay($due);
                $task = new Task($title, $year, $dueDate, TaskType::SIMPLE);
                $task->setUnit($owner->getUnit())
                    ->setAssignedUser($owner)
                    ->setCheckboxDone($done)
                    ->setStatus($done ? 'validated' : 'pending')
                    ->setCreatedBy($director);
                if (false !== $ownerRole && null !== $owner->getUnit()) {
                    $task->setResponsibility(new TaskResponsibility($ownerRole, $owner->getUnit()));
                }
                if ($done) {
                    $task->setCompletedBy($owner);
                }
                $this->em->persist($task);
                $tasks[] = $task;
            }
        }

        return ['events' => $events, 'tasks' => $tasks];
    }

    /**
     * Seeds a few inbox notifications tied to the personal tasks (a couple unread, one already read),
     * so the inbox and its badge are not empty.
     *
     * @param array{tasks: list<Task>} $agenda the personal agenda output
     *
     * @return int the number of notifications created
     */
    private function seedNotifications(array $agenda): int
    {
        $count = 0;
        foreach ($agenda['tasks'] as $position => $task) {
            $recipient = $task->getAssignedUser();
            if (null === $recipient) {
                continue;
            }
            $overdue = $task->getDueDate() < new \DateTimeImmutable('today');
            $notification = new Notification(
                $recipient,
                'task.reminder',
                sprintf('Tarea %s: %s', $overdue ? 'vencida' : 'próxima', $task->getTitle()),
                $overdue ? 'Se pasó la fecha límite.' : 'Vence pronto.',
                $task,
            );
            if (0 === $position) {
                $notification->markRead();
            }
            $this->em->persist($notification);
            ++$count;
        }

        return $count;
    }

    /**
     * Seeds an invented guardia rota for the whole course so the coordinator's analytics dashboard has
     * something to show: {@see GuardiaCover} rows spread across the three terms, weekdays (Mon–Fri) and
     * period slots, with a realistic mix of states (mostly covered, a few incidents, some left
     * unassigned) and a deliberately uneven split of covering teachers so the equity/Gini reading is
     * meaningful. Written directly (not via {@see \App\Guardia\AbsenceRegistrar}) because the demo needs
     * a controlled distribution and does not depend on a real imported timetable — the stats read only
     * from {@see GuardiaCover}, whose group/room are self-contained snapshots.
     *
     * The RNG is seeded ({@see self::GUARDIA_SEED}) so the same rota comes out on every run, and the
     * per-teacher/day/slot UNIQUE constraint is respected by skipping collisions.
     *
     * @param list<User>   $users        all real people (only active ones take part in the rota)
     * @param AcademicYear $academicYear the course whose term windows anchor the dates
     *
     * @return int the number of covers created
     */
    private function seedGuardias(array $users, AcademicYear $academicYear): int
    {
        $teachers = array_values(array_filter($users, static fn (User $u): bool => $u->isActive()));
        if (\count($teachers) < 4) {
            return 0; // sin claustro suficiente no hay reparto que enseñar
        }

        // Semilla desplazada por año: reproducible, pero cada curso con su propio patrón (no dos iguales).
        mt_srand(self::GUARDIA_SEED + (int) substr($academicYear->getSchoolYear(), 0, 4));

        // Pool de profesores de guardia con carga desigual: el primer cuarto cubre el triple y el
        // siguiente cuarto el doble que el resto, para que la equidad/Gini tenga contraste que mostrar.
        $coverPool = $this->weightedPool($teachers, [3, 2, 1, 1]);
        // Ausentes con otra desigualdad independiente: unos pocos faltan bastante más (ranking de ausencias).
        $absentPool = $this->weightedPool($teachers, [3, 1, 1, 1, 1]);

        $groups = ['1º ESO A', '1º ESO B', '2º ESO A', '3º ESO B', '4º ESO A', '1º BACH A', '2º BACH B', 'FPB I'];
        $rooms = ['A-12', 'A-14', 'B-03', 'B-21', 'Lab 2', 'Gimnasio', 'Taller', 'Aula TIC'];
        $descriptions = [null, null, 'Ejercicios 3–7 de la página 84.', 'Examen: vigilar y recoger las hojas.', 'Ver el vídeo indicado y resumen en el cuaderno.', 'Terminar la ficha de la sesión anterior.'];
        // Motivo de la ausencia (privado): la mayoría en blanco, unos pocos con un motivo de ejemplo.
        $reasons = [null, null, null, 'Cita médica.', 'Asuntos propios.', 'Formación externa.'];

        $seen = [];
        $absences = []; // una Absence por (profesor ausente, día); las horas del mismo día la comparten
        $created = 0;
        for ($term = 1; $term <= 3; ++$term) {
            $from = $academicYear->getTermStart($term);
            $span = max(1, (int) $from->diff($academicYear->getTermEnd($term))->days);

            for ($n = 0; $n < self::GUARDIAS_PER_TERM; ++$n) {
                $date = $this->randomWeekday($from, $span);
                $slot = mt_rand(0, 5);
                $absent = $absentPool[array_rand($absentPool)];

                $key = $absent->getId().'|'.$date->format('Y-m-d').'|'.$slot;
                if (isset($seen[$key])) {
                    continue; // respeta el UNIQUE (profesor ausente, día, tramo)
                }
                $seen[$key] = true;

                // Absence compartida por todas las horas del mismo profesor y día (donde vive el motivo).
                $absenceKey = $absent->getId().'|'.$date->format('Y-m-d');
                $absence = $absences[$absenceKey] ?? null;
                if (null === $absence) {
                    $absence = (new Absence())
                        ->setAbsentTeacher($absent)
                        ->setDate($date)
                        ->setReason($reasons[array_rand($reasons)]);
                    $this->em->persist($absence);
                    $absences[$absenceKey] = $absence;
                }

                $cover = (new GuardiaCover())
                    ->setAbsence($absence)
                    ->setDate($date)
                    ->setSlotIndex($slot)
                    ->setAbsentTeacher($absent)
                    ->setGroupName($groups[array_rand($groups)])
                    ->setRoomName($rooms[array_rand($rooms)])
                    ->setTaskDescription($descriptions[array_rand($descriptions)]);

                // Estado: ~78% cubiertas, ~10% incidencias (había guardia asignado pero no se cubrió),
                // ~12% sin asignar (no quedaba nadie libre en el pool).
                $roll = mt_rand(1, 100);
                if ($roll <= 12) {
                    // sin asignar: assignedGuardia null, notCovered false (por defecto)
                } elseif ($roll <= 22) {
                    $cover->setAssignedGuardia($this->pickOther($coverPool, $absent))->setNotCovered(true);
                } else {
                    $cover->setAssignedGuardia($this->pickOther($coverPool, $absent));
                }

                $this->em->persist($cover);
                ++$created;
            }
        }

        return $created;
    }

    /**
     * Builds a weighted pick pool: the shuffled teachers are cut into bands and each band is repeated as
     * many times as its weight, so {@see array_rand()} over the pool favours the heavier bands. Shuffling
     * (seeded) keeps the unevenness from always landing on the same people across runs of different sizes.
     *
     * @param list<User> $teachers the teachers to spread
     * @param list<int>  $weights  the repetition weight per band, in order (last one covers the tail)
     *
     * @return list<User> the pick pool (a teacher appears once per weight unit)
     */
    private function weightedPool(array $teachers, array $weights): array
    {
        $shuffled = $teachers;
        shuffle($shuffled);
        $bands = \count($weights);
        $bandSize = (int) ceil(\count($shuffled) / $bands);

        $pool = [];
        foreach ($shuffled as $i => $teacher) {
            $weight = $weights[min((int) ($i / max(1, $bandSize)), $bands - 1)];
            for ($w = 0; $w < $weight; ++$w) {
                $pool[] = $teacher;
            }
        }

        return $pool;
    }

    /**
     * A random teaching-week date (Mon–Fri) within {@code $span} days of {@code $from}; weekends are
     * re-rolled so the heatmap only ever shows teaching days.
     *
     * @param \DateTimeImmutable $from the window start
     * @param int                $span the window length in days
     *
     * @return \DateTimeImmutable a weekday inside the window
     */
    private function randomWeekday(\DateTimeImmutable $from, int $span): \DateTimeImmutable
    {
        do {
            $date = $from->modify('+'.mt_rand(0, $span).' days');
        } while ((int) $date->format('N') > 5);

        return $date;
    }

    /**
     * Picks a teacher from the pool other than the given one (an absent teacher cannot cover their own
     * guardia). Falls back to any pick when the pool has no alternative.
     *
     * @param list<User> $pool    the weighted pick pool
     * @param User       $exclude the teacher to avoid
     *
     * @return User the chosen teacher
     */
    private function pickOther(array $pool, User $exclude): User
    {
        do {
            $pick = $pool[array_rand($pool)];
        } while ($pick === $exclude && \count($pool) > 1);

        return $pick;
    }

    /**
     * The first active holder of a role, or null when nobody holds it.
     *
     * @param Role $role the role to resolve
     *
     * @return User|null the first active holder, or null
     */
    private function firstHolder(Role $role): ?User
    {
        foreach ($role->getUsers() as $user) {
            if ($user->isActive()) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Lowercases and strips Spanish accents for accent-insensitive matching.
     *
     * @param string $text the text to fold
     *
     * @return string the folded text
     */
    private function fold(string $text): string
    {
        return strtr(mb_strtolower($text, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    }
}
