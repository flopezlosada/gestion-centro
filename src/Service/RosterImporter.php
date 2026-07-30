<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Department;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Loads the teaching staff roster — people, their department and their cargo — from a normalised CSV
 * ({@code full_name,email,department,cargo}).
 *
 * Extracted from the console command so the same code serves the self-service screen the equipo
 * directivo uses each September: the matching rules (people by e-mail, departments and roles by a
 * stable slug code) must not exist twice, or a mid-year re-import from one entry point would duplicate
 * what the other created.
 *
 * Idempotent: re-running after a change only updates what moved. A dry run resolves exactly the same
 * and writes NOTHING — not even a department or a role — so previewing an import can never half-apply
 * it, whatever flushes next.
 *
 * What it does NOT do: department heads are not in the source, so units are left without one; and only
 * "Dirección" gets back-office access. Every other role is created as a plain responsibility marker, so
 * any further permission stays a deliberate decision.
 */
final class RosterImporter
{
    /** Expected CSV header, in order. */
    public const array HEADER = ['full_name', 'email', 'department', 'cargo'];

    /** @var array<string, Department> units by code, built during a run */
    private array $units = [];

    /** @var array<string, Role> roles by code, built during a run */
    private array $roles = [];

    private readonly AsciiSlugger $slugger;

    public function __construct(private readonly EntityManagerInterface $em)
    {
        $this->slugger = new AsciiSlugger();
    }

    /**
     * Imports (or previews) a roster CSV.
     *
     * @param string $csv    the CSV contents
     * @param bool   $dryRun when true, analyses and reports without writing anything
     *
     * @return RosterImportResult who would be created, who updated, and what could not be read
     *
     * @throws \RuntimeException if the header is not the expected one
     */
    public function import(string $csv, bool $dryRun = false): RosterImportResult
    {
        $this->units = [];
        $this->roles = [];

        [$rows, $skipped] = $this->parse($csv);

        $created = [];
        $updated = 0;
        foreach ($rows as $row) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $row['email']]);
            if (null === $user) {
                $created[] = $row['full_name'];
                if ($dryRun) {
                    // Still resolve the department and cargo below? No: nothing is written on a dry run,
                    // and a person who does not exist yet has nothing to compare against.
                    continue;
                }
                $user = new User();
                $this->em->persist($user);
            } else {
                ++$updated;
                if ($dryRun) {
                    continue;
                }
            }

            $user->setFullName($row['full_name'])->setEmail($row['email'])->setUnit($this->unit($row['department']));
            $user->addAssignedRole($this->role('teacher', 'Docente'));
            $cargoRole = $this->roleForCargo($row['cargo']);
            if (null !== $cargoRole) {
                $user->addAssignedRole($cargoRole);
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        return new RosterImportResult(
            \count($rows),
            $created,
            $updated,
            $dryRun ? $this->departmentsIn($rows) : array_keys($this->units),
            $skipped,
            $dryRun,
        );
    }

    /**
     * Reads the CSV into rows, reporting the lines it had to skip rather than dropping them silently.
     *
     * @param string $csv the CSV contents
     *
     * @return array{0: list<array{full_name: string, email: string, department: string, cargo: string}>, 1: list<string>}
     *                                                                                                                    the rows and a description of each skipped line
     *
     * @throws \RuntimeException if the header is not the expected one
     */
    private function parse(string $csv): array
    {
        $lines = preg_split('/\R/', trim($csv)) ?: [];
        $header = str_getcsv((string) array_shift($lines));
        if ($header !== self::HEADER) {
            throw new \RuntimeException(sprintf('La cabecera del CSV debe ser exactamente: %s', implode(',', self::HEADER)));
        }

        $rows = [];
        $skipped = [];
        foreach ($lines as $number => $line) {
            if ('' === trim($line)) {
                continue;
            }

            $cells = str_getcsv($line);
            if (\count($cells) < 4 || '' === trim((string) ($cells[1] ?? ''))) {
                $skipped[] = sprintf('Línea %d: sin correo o con menos de 4 columnas.', $number + 2);
                continue;
            }

            $rows[] = [
                'full_name' => trim((string) $cells[0]),
                'email' => mb_strtolower(trim((string) $cells[1])),
                'department' => trim((string) $cells[2]),
                'cargo' => trim((string) $cells[3]),
            ];
        }

        return [$rows, $skipped];
    }

    /**
     * The distinct department names a set of rows names — what a dry run reports without creating
     * anything.
     *
     * @param list<array{full_name: string, email: string, department: string, cargo: string}> $rows the rows
     *
     * @return list<string> the department names
     */
    private function departmentsIn(array $rows): array
    {
        $names = array_unique(array_filter(array_map(static fn (array $r): string => $r['department'], $rows)));
        // sort() reindexes, so the result is already a list.
        sort($names);

        return $names;
    }

    /**
     * Gets or creates the department for a name, keyed by a stable slug code.
     *
     * @param string $name the department name
     *
     * @return Department the (new or existing) department
     */
    private function unit(string $name): Department
    {
        $code = 'dept_'.$this->slugger->slug($name, '_')->lower()->toString();
        if (isset($this->units[$code])) {
            return $this->units[$code];
        }

        $unit = $this->em->getRepository(Department::class)->findOneBy(['code' => $code])
            ?? (new Department())->setCode($code);
        $unit->setName($name);
        $this->em->persist($unit);

        return $this->units[$code] = $unit;
    }

    /**
     * Gets or creates a role by code, applying an optional configurator.
     *
     * @param string                   $code      the stable role code
     * @param string                   $name      the display name
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

        // Codes match the app's canonical role catalog (see RoleFixtures), so a golden seed + import
        // upserts the same roles rather than creating Spanish duplicates.
        return match (true) {
            str_contains($c, 'DIRECTOR') => $this->role('direction', 'Dirección', static function (Role $r): void {
                $r->setLevel(Area::ADMINISTRATION, PermissionLevel::WRITE);
            }),
            str_contains($c, 'ADJ') => $this->role('head_of_studies_deputy', 'Jefatura de estudios adjunta'),
            str_contains($c, 'JEFE DE ESTUDIOS'), str_contains($c, 'JEFA DE ESTUDIOS') => $this->role('head_of_studies', 'Jefatura de estudios'),
            str_contains($c, 'SECRETARI') => $this->role('secretary', 'Secretaría'),
            str_starts_with($c, 'TUTOR') => $this->role('tutor', 'Tutor/a'),
            default => null,
        };
    }
}
