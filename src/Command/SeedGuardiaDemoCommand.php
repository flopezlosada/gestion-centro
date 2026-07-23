<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Guardia\AbsenceRegistrar;
use App\Repository\AcademicYearRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\UserRepository;
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
 * DEV/DEMO seeder for the daily guardia flow, layered ON TOP of an already-imported timetable.
 *
 * Peñalara GHC exports the lective timetable but usually NOT the guardia duties, so a freshly imported
 * course has no duty pool and the parte cannot assign anyone — teachers see an empty module. This
 * command makes the demo lifelike without touching the imported lessons:
 *
 *  1. Synthesises a guardia duty pool: for each active teacher it marks a few of their FREE periods as
 *     {@see ScheduleActivityKind::GUARDIA} (via {@see ScheduleEntryRepository::replaceDutySlotsForTeacher()},
 *     which only ever replaces duty cells, never the imported lessons).
 *  2. Registers absences for TODAY and TOMORROW for a handful of teachers through the real
 *     {@see AbsenceRegistrar}, so covers are generated and auto-assigned from that pool exactly as in
 *     production — the parte of both days shows real, assigned guardias.
 *
 * Idempotent: the duty cells are replaced per teacher and the covers of the two target days are cleared
 * before regenerating. Requires the course structure and an imported timetable to exist. Refused in
 * prod unless {@code --force} (this box is a staging that runs as prod); see the tech-debt note.
 *
 * WARNING: registering absences runs the real assignment, which notifies the assigned teachers. On a
 * server with real people that produces real in-app notifications.
 */
#[AsCommand(name: 'app:seed-guardia-demo', description: 'DEV: crea horario de guardias sintético (pool) y ausencias de hoy/mañana sobre el horario importado')]
final class SeedGuardiaDemoCommand extends Command
{
    /** Fixed RNG seed so the invented duty pool and absences are the same on every run. */
    private const int SEED = 20260723;

    /** Percentage chance a teacher gets a guardia duty on a given weekday (spreads ~3 duties/week). */
    private const int DUTY_WEEKDAY_CHANCE = 60;

    /** How many teachers are marked absent per target day (today and tomorrow). */
    private const int ABSENCES_PER_DAY = 8;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AcademicYearRepository $years,
        private readonly ScheduleEntryRepository $schedule,
        private readonly UserRepository $users,
        private readonly AbsenceRegistrar $registrar,
        #[Autowire('%kernel.environment%')] private readonly string $env,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Permite ejecutarlo aunque el entorno sea prod (staging que corre como prod). NO usar en prod real.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->env && !$input->getOption('force')) {
            $io->error('Genera datos inventados y no puede ejecutarse en producción. Usa --force solo si este entorno es un staging.');

            return Command::FAILURE;
        }
        if ('prod' === $this->env) {
            $io->warning('Ejecutando en PROD por --force: se generarán guardias y ausencias inventadas (y se notificará a los asignados). Asegúrate de que es un staging.');
        }

        $year = $this->years->findBySchoolYear(SchoolYear::current(new \DateTimeImmutable('today')));
        if (!$year instanceof AcademicYear) {
            $io->error('No existe el curso actual. Ejecuta antes app:seed-demo (crea la estructura del curso).');

            return Command::FAILURE;
        }

        $slots = $this->schedule->distinctSlots($year);
        if ([] === $slots) {
            $io->error('No hay horario importado para el curso actual. Impórtalo primero en /admin/horario y vuelve a ejecutar.');

            return Command::FAILURE;
        }

        mt_srand(self::SEED);

        /** @var list<User> $teachers */
        $teachers = $this->users->findBy(['active' => true], ['fullName' => 'ASC']);

        $duties = $this->seedDutyPool($year, $teachers, $slots);
        [$absencesByDay, $coversAssigned] = $this->seedAbsences($year, $teachers);

        $io->success(sprintf('Guardias demo generadas sobre el horario de %s.', $year->getSchoolYear()));
        $io->table(['Elemento', 'Creado'], [
            ['Slots de guardia (pool) sintéticos', (string) $duties],
            ['Ausencias hoy', (string) ($absencesByDay['today'] ?? 0)],
            ['Ausencias mañana', (string) ($absencesByDay['tomorrow'] ?? 0)],
            ['Guardias (coberturas) generadas', (string) $coversAssigned],
        ]);

        return Command::SUCCESS;
    }

    /**
     * Marks a few free periods of every teacher as guardia duty, building the pool the parte assigns
     * from. Only duty cells are touched (the imported lessons stay).
     *
     * @param AcademicYear                                                                          $year     the course
     * @param list<User>                                                                            $teachers active teachers
     * @param list<array{index: int, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}>     $slots    the course's periods with their times
     *
     * @return int the number of guardia duty cells created
     */
    private function seedDutyPool(AcademicYear $year, array $teachers, array $slots): int
    {
        $timeOf = [];
        foreach ($slots as $slot) {
            $timeOf[$slot['index']] = $slot;
        }
        $allSlots = array_keys($timeOf);

        $created = 0;
        foreach ($teachers as $teacher) {
            $entries = [];
            foreach ([1, 2, 3, 4, 5] as $weekday) {
                if (mt_rand(1, 100) > self::DUTY_WEEKDAY_CHANCE) {
                    continue; // ese día no tiene guardia asignada
                }
                $free = array_values(array_diff($allSlots, $this->schedule->lectiveSlotsFor($year, $teacher, Weekday::from($weekday))));
                if ([] === $free) {
                    continue; // día completo de clase: no hay hueco para guardia
                }
                $slot = $free[mt_rand(0, \count($free) - 1)];
                $entries[] = (new ScheduleEntry())
                    ->setAcademicYear($year)
                    ->setTeacher($teacher)
                    ->setWeekday(Weekday::from($weekday))
                    ->setSlotIndex($slot)
                    ->setStartsAt($timeOf[$slot]['startsAt'])
                    ->setEndsAt($timeOf[$slot]['endsAt'])
                    ->setKind(ScheduleActivityKind::GUARDIA);
            }
            // Reemplaza solo las celdas de guardia del profesor (nunca sus clases) → idempotente.
            $this->schedule->replaceDutySlotsForTeacher($year, $teacher, $entries);
            $created += \count($entries);
        }

        return $created;
    }

    /**
     * Registers absences for today and tomorrow (teaching days only) through the real registrar, which
     * auto-assigns each cover from the duty pool. Clears the covers of each target day first so a re-run
     * regenerates cleanly.
     *
     * @param AcademicYear $year     the course
     * @param list<User>   $teachers active teachers
     *
     * @return array{0: array{today?: int, tomorrow?: int}, 1: int} absences registered per day, and total covers created
     */
    private function seedAbsences(AcademicYear $year, array $teachers): array
    {
        $byDay = [];
        $coversTotal = 0;
        $targets = ['today' => new \DateTimeImmutable('today'), 'tomorrow' => new \DateTimeImmutable('tomorrow')];

        foreach ($targets as $label => $date) {
            if ((int) $date->format('N') > 5) {
                $byDay[$label] = 0; // fin de semana: no hay clases que cubrir
                continue;
            }
            $weekday = Weekday::from((int) $date->format('N'));

            // Idempotencia: borra las coberturas de ese día antes de regenerarlas.
            $this->em->createQuery('DELETE FROM '.GuardiaCover::class.' c WHERE c.date = :d')
                ->setParameter('d', $date)
                ->execute();

            // Solo profesores con clase ese día del que haya guardia que generar.
            $candidates = array_values(array_filter(
                $teachers,
                fn (User $t): bool => [] !== $this->schedule->lectiveSlotsFor($year, $t, $weekday),
            ));
            shuffle($candidates);

            $count = 0;
            foreach (\array_slice($candidates, 0, self::ABSENCES_PER_DAY) as $teacher) {
                $result = $this->registrar->register($year, $teacher, $date, null, 'Ejercicios de repaso del tema; se recogen al final de la hora.');
                $coversTotal += $result->createdCount();
                ++$count;
            }
            $byDay[$label] = $count;
        }

        return [$byDay, $coversTotal];
    }
}
