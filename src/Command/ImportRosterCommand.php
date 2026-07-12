<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Role;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Imports the teaching staff roster (people, departments and their role) from a normalised CSV
 * (`full_name,email,department,cargo`, produced by import/normalize_roster.py) into the database.
 *
 * Idempotent: people are matched by e-mail, departments and roles by a stable code, so re-running
 * after a mid-year change only updates what moved and never duplicates. The roster is personal data
 * and lives OUTSIDE the repository (a gitignored CSV); this command is the code that loads it.
 *
 * What it does NOT do: department heads/managers are not in the source, so unit managers are left
 * unset; and only the "Dirección" role is granted back-office access — every other role is created
 * as a plain responsibility marker, so any further permission is a deliberate admin decision.
 */
#[AsCommand(name: 'app:import-roster', description: 'Importa el claustro (personas, departamentos y cargo) desde un CSV normalizado')]
final class ImportRosterCommand extends Command
{
    /** @var array<string, Unit> units by code, built during the run */
    private array $units = [];

    /** @var array<string, Role> roles by code, built during the run */
    private array $roles = [];

    private readonly AsciiSlugger $slugger;

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
        $this->slugger = new AsciiSlugger();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('csv', InputArgument::REQUIRED, 'Ruta al CSV normalizado (full_name,email,department,cargo)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analiza y muestra el resumen sin escribir en la base de datos');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('csv');
        $dryRun = (bool) $input->getOption('dry-run');

        $rows = $this->readCsv($path, $io);
        if (null === $rows) {
            return Command::FAILURE;
        }

        $usersCreated = 0;
        $usersUpdated = 0;
        foreach ($rows as $row) {
            $unit = $this->unit($row['department']);

            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $row['email']]);
            if (null === $user) {
                $user = new User();
                $this->em->persist($user);
                ++$usersCreated;
            } else {
                ++$usersUpdated;
            }

            $user->setFullName($row['full_name'])->setEmail($row['email'])->setUnit($unit);
            $user->addAssignedRole($this->role('docente', 'Docente'));
            $cargoRole = $this->roleForCargo($row['cargo']);
            if (null !== $cargoRole) {
                $user->addAssignedRole($cargoRole);
            }
        }

        if ($dryRun) {
            $io->warning('Dry-run: nada escrito.');
        } else {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%d docentes (%d nuevos, %d actualizados), %d departamentos, %d roles.%s',
            \count($rows),
            $usersCreated,
            $usersUpdated,
            \count($this->units),
            \count($this->roles),
            $dryRun ? ' [dry-run]' : '',
        ));
        $io->note('Los jefes de departamento no vienen en el origen: las unidades quedan sin responsable. Solo "Dirección" recibe acceso al back-office; el resto son marcadores.');

        return Command::SUCCESS;
    }

    /**
     * Reads and validates the normalised CSV.
     *
     * @return list<array{full_name: string, email: string, department: string, cargo: string}>|null
     *                                                                                               the rows, or null on error
     */
    private function readCsv(string $path, SymfonyStyle $io): ?array
    {
        if (!is_readable($path)) {
            $io->error(sprintf('No se puede leer el CSV: %s', $path));

            return null;
        }

        $handle = fopen($path, 'r');
        if (false === $handle) {
            $io->error(sprintf('No se pudo abrir el CSV: %s', $path));

            return null;
        }

        $header = fgetcsv($handle);
        $expected = ['full_name', 'email', 'department', 'cargo'];
        if ($header !== $expected) {
            $io->error(sprintf('Cabecera inesperada. Se esperaba: %s', implode(',', $expected)));
            fclose($handle);

            return null;
        }

        $rows = [];
        while (false !== ($line = fgetcsv($handle))) {
            if (\count($line) < 4 || '' === trim((string) $line[1])) {
                continue;
            }
            $rows[] = [
                'full_name' => trim((string) $line[0]),
                'email' => strtolower(trim((string) $line[1])),
                'department' => trim((string) $line[2]),
                'cargo' => trim((string) $line[3]),
            ];
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Gets or creates the department unit for a name, keyed by a stable slug code.
     *
     * @param string $name the department name
     *
     * @return Unit the (new or existing) unit
     */
    private function unit(string $name): Unit
    {
        $code = 'dept_'.$this->slugger->slug($name, '_')->lower()->toString();
        if (isset($this->units[$code])) {
            return $this->units[$code];
        }

        $unit = $this->em->getRepository(Unit::class)->findOneBy(['code' => $code])
            ?? (new Unit())->setCode($code);
        $unit->setName($name);
        $this->em->persist($unit);

        return $this->units[$code] = $unit;
    }

    /**
     * Gets or creates a role by code, applying an optional configurator on creation and re-runs.
     *
     * @param string             $code      the stable role code
     * @param string             $name      the display name
     * @param callable(Role):void|null $configure optional extra setup (e.g. permissions)
     *
     * @return Role the (new or existing) role
     */
    private function role(string $code, string $name, ?callable $configure = null): Role
    {
        if (isset($this->roles[$code])) {
            return $this->roles[$code];
        }

        $role = $this->em->getRepository(Role::class)->findOneBy(['code' => $code])
            ?? (new Role())->setCode($code);
        $role->setName($name);
        if (null !== $configure) {
            $configure($role);
        }
        $this->em->persist($role);

        return $this->roles[$code] = $role;
    }

    /**
     * Maps a raw "cargo" cell to its role, or null when it carries no special role.
     *
     * @param string $cargo the raw cargo text (e.g. "TUTORA E3B", "JEFA DE ESTUDIOS")
     *
     * @return Role|null the role, or null
     */
    private function roleForCargo(string $cargo): ?Role
    {
        $c = mb_strtoupper($cargo);

        return match (true) {
            str_contains($c, 'DIRECTOR') => $this->role('direccion', 'Dirección', static function (Role $r): void {
                $r->setLevel(Area::ADMINISTRATION, PermissionLevel::WRITE);
            }),
            str_contains($c, 'ADJ') => $this->role('jefatura_adjunta', 'Jefatura de estudios adjunta'),
            str_contains($c, 'JEFE DE ESTUDIOS'), str_contains($c, 'JEFA DE ESTUDIOS') => $this->role('jefatura_estudios', 'Jefatura de estudios'),
            str_contains($c, 'SECRETARI') => $this->role('secretaria', 'Secretaría'),
            str_starts_with($c, 'TUTOR') => $this->role('tutor', 'Tutor/a'),
            default => null,
        };
    }
}
