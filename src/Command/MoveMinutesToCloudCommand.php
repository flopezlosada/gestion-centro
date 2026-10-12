<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Meeting;
use App\Repository\MeetingRepository;
use App\Service\Cloud\CloudAccount;
use App\Service\Cloud\CloudException;
use App\Service\Cloud\CloudMinutes;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Moves the actas written in the app — before the EducaMadrid cloud — to the cloud, so their text and
 * files stop living in this database and its backups ({@see CloudMinutes::moveToCloud()}).
 *
 * Run by whoever operates the server, never by reading the actas: it prints meeting ids and counts, not a
 * line of what they say. --dry-run only counts. Each meeting is saved as soon as it has moved, and its file
 * here deleted after that, so a failure halfway leaves every acta either here or there, never lost; run it
 * again and it carries on.
 */
#[AsCommand(name: 'app:minutes:move-to-cloud', description: 'Pasa a la nube de EducaMadrid las actas escritas en la aplicación')]
final class MoveMinutesToCloudCommand extends Command
{
    public function __construct(
        private readonly MeetingRepository $meetings,
        private readonly CloudAccount $account,
        private readonly CloudMinutes $cloudMinutes,
        private readonly FileUploader $uploader,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Solo cuenta lo que se movería; no toca nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pending = $this->meetings->findMinutesWrittenInApp();
        $io->table(['Actas escritas en la app', 'Cuántas'], self::census($pending, $this->fileIsMissing(...)));

        if ($input->getOption('dry-run') || [] === $pending) {
            return Command::SUCCESS;
        }
        if (!$this->account->isConnected()) {
            $io->error('La nube de EducaMadrid no está conectada (Administración → Nube de EducaMadrid).');

            return Command::FAILURE;
        }

        $failed = 0;
        foreach ($pending as $meeting) {
            $failed += $this->move($meeting, $io) ? 0 : 1;
        }

        if ($failed > 0) {
            $io->warning(\sprintf('%d de %d actas no se han movido; siguen en la aplicación. Corrige la causa y vuelve a lanzarlo.', $failed, \count($pending)));

            return Command::FAILURE;
        }
        $io->success(\sprintf('%d actas movidas a la nube.', \count($pending)));

        return Command::SUCCESS;
    }

    /**
     * Moves one acta, saves it, and only then deletes its file here.
     *
     * @return bool whether it moved
     */
    private function move(Meeting $meeting, SymfonyStyle $io): bool
    {
        $id = $meeting->getId();
        $path = $meeting->getMinutesPath();
        if (null !== $path && $this->fileIsMissing($meeting)) {
            $io->error(\sprintf('Reunión %d: su fichero no está en el disco; no se toca.', $id));

            return false;
        }

        try {
            $problems = $this->cloudMinutes->moveToCloud($meeting, null === $path ? null : (string) file_get_contents($this->uploader->absolutePath($path)));
            $this->entityManager->flush();
        } catch (CloudException $e) {
            $this->entityManager->refresh($meeting);
            $io->error(\sprintf('Reunión %d: %s', $id, $e->getMessage()));

            return false;
        }

        if (null !== $path) {
            $this->uploader->remove($path);
        }
        $io->writeln(\sprintf('Reunión %d: movida%s.', $id, [] === $problems ? '' : \sprintf(' (sin compartir con %d persona%s)', \count($problems), 1 === \count($problems) ? '' : 's')));
        foreach ($problems as $problem) {
            $io->writeln('   · '.$problem, OutputInterface::VERBOSITY_VERBOSE);
        }

        return true;
    }

    private function fileIsMissing(Meeting $meeting): bool
    {
        $path = $meeting->getMinutesPath();

        return null !== $path && !is_file($this->uploader->absolutePath($path));
    }

    /**
     * How many there are of each kind, without reading a word of them.
     *
     * @param list<Meeting>           $meetings
     * @param callable(Meeting): bool $missing  whether a meeting's file is gone from the disk
     *
     * @return list<array{string, int}>
     */
    private static function census(array $meetings, callable $missing): array
    {
        $count = static fn (callable $test): int => \count(array_filter($meetings, $test));
        $hasText = static fn (Meeting $m): bool => null !== $m->getDiscussion() || null !== $m->getAgreements();

        return [
            ['Total', \count($meetings)],
            ['Solo texto', $count(static fn (Meeting $m): bool => $hasText($m) && null === $m->getMinutesPath())],
            ['Solo fichero', $count(static fn (Meeting $m): bool => !$hasText($m) && null !== $m->getMinutesPath())],
            ['Texto y fichero', $count(static fn (Meeting $m): bool => $hasText($m) && null !== $m->getMinutesPath())],
            ['Publicadas', $count(static fn (Meeting $m): bool => $m->isMinutesPublished())],
            ['Aprobadas', $count(static fn (Meeting $m): bool => $m->areMinutesApproved())],
            ['Con el fichero perdido en el disco', $count($missing)],
        ];
    }
}
