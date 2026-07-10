<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

/**
 * Stores uploaded files in a private directory (outside the public web root), under per-feature
 * subdirectories. Reusable across modules (consumption invoices, signed PDFs, evidence...).
 *
 * Validation of the upload (size, mime type) is left to the form layer; this service only takes
 * care of safe, collision-free storage and retrieval. Stored names are random UUIDs, so the
 * client filename never reaches the filesystem and path traversal is not possible.
 */
final class FileUploader
{
    private readonly Filesystem $filesystem;

    /**
     * @param string $uploadsDir absolute base directory for private uploads (bound from %app.uploads_dir%)
     */
    public function __construct(private readonly string $uploadsDir)
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * Stores an uploaded file under the given subdirectory and returns its storage-relative path.
     *
     * @param UploadedFile $file   the uploaded file
     * @param string       $subdir feature subdirectory (e.g. "consumption-invoices")
     *
     * @return string the relative path under the uploads dir (e.g. "consumption-invoices/<uuid>.pdf")
     */
    public function upload(UploadedFile $file, string $subdir): string
    {
        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension();
        $name = $this->randomName($extension);

        $relativeDir = trim($subdir, '/');
        $file->move($this->uploadsDir.'/'.$relativeDir, $name);

        return $relativeDir.'/'.$name;
    }

    /**
     * Stores raw file contents (e.g. a generated PDF) under the given subdirectory and returns its
     * storage-relative path. The in-memory counterpart of {@see upload()} for artefacts the app
     * generates rather than receives.
     *
     * @param string $contents  the raw bytes to persist
     * @param string $subdir    feature subdirectory (e.g. "document-pdfs")
     * @param string $extension file extension without the dot (e.g. "pdf")
     *
     * @return string the relative path under the uploads dir (e.g. "document-pdfs/<uuid>.pdf")
     */
    public function store(string $contents, string $subdir, string $extension): string
    {
        $name = $this->randomName($extension);
        $relativeDir = trim($subdir, '/');
        $this->filesystem->dumpFile($this->uploadsDir.'/'.$relativeDir.'/'.$name, $contents);

        return $relativeDir.'/'.$name;
    }

    /**
     * A collision-free, opaque file name (random UUID) so the client filename never reaches the
     * filesystem and path traversal is not possible.
     */
    private function randomName(string $extension): string
    {
        $extension = ltrim($extension, '.');

        return Uuid::v4()->toRfc4122().('' !== $extension ? '.'.$extension : '');
    }

    /**
     * Absolute path of a stored file, for serving it back.
     *
     * @param string $relativePath the storage-relative path returned by {@see upload()}
     *
     * @return string the absolute filesystem path
     */
    public function absolutePath(string $relativePath): string
    {
        return $this->uploadsDir.'/'.$relativePath;
    }

    /**
     * Removes a stored file if it exists (no-op otherwise). Used to avoid orphan files when an
     * attachment is replaced.
     *
     * @param string $relativePath the storage-relative path returned by {@see upload()}
     */
    public function remove(string $relativePath): void
    {
        $this->filesystem->remove($this->absolutePath($relativePath));
    }
}
