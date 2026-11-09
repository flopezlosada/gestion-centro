<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\FamilyVolunteerRepository;
use App\Util\SchoolYear;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes the family accounts of past courses, with everything that hangs from them (signups, days
 * available, consents), when a new course starts on 1 September ({@see SchoolYear}).
 *
 * Families volunteer for a course: what the families' data-protection text promises is that their data
 * is kept until the course ends. A family that comes back the next year is created again by the centre
 * and accepts the text again. An account created in summer for the coming course is also deleted on
 * 1 September: the line is drawn by date, simple to state and to check, and the summer is not when the
 * centre creates accounts.
 *
 * Safe on its first tick after a deploy (which runs at once, not at its hour): it only deletes accounts
 * of a course already over, and the families' area was first deployed during the current one.
 */
#[AsCommand(name: 'app:families:purge-past-course-accounts', description: 'Borra las cuentas de familias de cursos pasados, con sus inscripciones, días y consentimientos')]
final class PurgePastCourseFamilyAccountsCommand extends AbstractCronCommand
{
    public function __construct(
        private readonly FamilyVolunteerRepository $families,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Ejecuta aunque la tarea esté pausada en los ajustes');
    }

    /**
     * Deletes the accounts created before the current course began.
     */
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        [$courseStart] = SchoolYear::bounds(SchoolYear::current($this->clock->now()));
        $deleted = $this->families->deleteCreatedBefore($courseStart);
        $detail = \sprintf('%d cuentas de familias de cursos pasados borradas (creadas antes del %s).', $deleted, $courseStart->format('d/m/Y'));
        $io->writeln($detail);

        return $deleted > 0 ? $this->didWork($detail) : $this->nothingToDo($detail);
    }
}
