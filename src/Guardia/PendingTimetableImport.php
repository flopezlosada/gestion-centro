<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Holds the two Peñalara exports between the "ver qué va a pasar" preview and the confirmation that
 * actually writes them. A browser upload lives for exactly one request, so without this the two-step
 * flow would mean picking both files again to confirm — and the point of the preview is to be read
 * calmly before committing.
 *
 * The bytes go to a private directory under var/ (they can be megabytes: too big for the session, and
 * they must not be reachable from the web root) and only an opaque token travels in the session, so a
 * request can never name someone else's upload or walk out of the directory. Whatever is pending is
 * dropped when the import is confirmed, when it is discarded, and when a new one is started; anything
 * an abandoned tab left behind is swept on the next upload.
 */
final class PendingTimetableImport
{
    /** Session key holding the token and target course of the upload awaiting confirmation. */
    private const SESSION_KEY = 'timetable_import.pending';

    /** How long an unconfirmed upload survives on disk before being swept, in seconds. */
    private const MAX_AGE = 3600;

    private readonly Filesystem $filesystem;

    /**
     * @param RequestStack $requestStack       the current request stack, for the session
     * @param string       $pendingImportsDir  absolute directory for uploads awaiting confirmation
     */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly string $pendingImportsDir,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Stores an upload as the pending import, replacing whatever was pending before.
     *
     * @param AcademicYear $year         the target course
     * @param UploadedFile $planificador the planificador export
     * @param UploadedFile $horario      the resolved timetable export
     */
    public function store(AcademicYear $year, UploadedFile $planificador, UploadedFile $horario): void
    {
        $this->discard();
        $this->sweep();

        $token = Uuid::v4()->toRfc4122();
        $dir = $this->pendingImportsDir.'/'.$token;
        $planificador->move($dir, 'planificador.xml');
        $horario->move($dir, 'horario.xml');

        $this->requestStack->getSession()->set(self::SESSION_KEY, ['token' => $token, 'yearId' => $year->getId()]);
    }

    /**
     * The upload awaiting confirmation, or null when there is none (or its files are gone).
     *
     * @return array{yearId: int, planificador: string, horario: string}|null the target course id and both documents
     */
    public function retrieve(): ?array
    {
        $pending = $this->requestStack->getSession()->get(self::SESSION_KEY);
        if (!\is_array($pending) || !isset($pending['token'], $pending['yearId']) || !\is_string($pending['token'])) {
            return null;
        }

        $dir = $this->pendingImportsDir.'/'.$pending['token'];
        if (!Uuid::isValid($pending['token']) || !is_readable($dir.'/planificador.xml') || !is_readable($dir.'/horario.xml')) {
            // Swept, deployed away or tampered with: behave as if nothing were pending.
            $this->discard();

            return null;
        }

        return [
            'yearId' => (int) $pending['yearId'],
            'planificador' => (string) file_get_contents($dir.'/planificador.xml'),
            'horario' => (string) file_get_contents($dir.'/horario.xml'),
        ];
    }

    /**
     * Forgets the pending upload and deletes its files. Safe to call when there is none.
     */
    public function discard(): void
    {
        $session = $this->requestStack->getSession();
        $pending = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);

        if (\is_array($pending) && isset($pending['token']) && \is_string($pending['token']) && Uuid::isValid($pending['token'])) {
            $this->filesystem->remove($this->pendingImportsDir.'/'.$pending['token']);
        }
    }

    /**
     * Deletes uploads older than {@see self::MAX_AGE} — the ones whose tab was closed on the preview.
     */
    private function sweep(): void
    {
        if (!is_dir($this->pendingImportsDir)) {
            return;
        }

        $deadline = time() - self::MAX_AGE;
        foreach ((array) glob($this->pendingImportsDir.'/*', \GLOB_ONLYDIR) as $dir) {
            if (\is_string($dir) && (int) filemtime($dir) < $deadline) {
                $this->filesystem->remove($dir);
            }
        }
    }
}
