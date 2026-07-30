<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The single policy for the documents the centre uploads to be read or printed later: the task an
 * absent teacher leaves, the sheets a department puts in the guardia task bank, anything sent to the
 * copy room. What is accepted has to be the same everywhere — a file the bank takes but a copy order
 * rejects would be a trap.
 *
 * Only the extension is checked, on purpose: sniffing the media type rejected legitimate Office
 * documents in this project before. It is defence in depth, not the defence itself — the real one is
 * that uploads live outside the web root and are only ever served as attachments.
 */
final class DocumentUpload
{
    /** Size ceiling for an uploaded document. */
    public const int MAX_BYTES = 10 * 1024 * 1024;

    /** Accepted extensions: what a teacher realistically hands a group or sends to be printed. */
    public const array ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt',
        'ppt', 'pptx', 'xls', 'xlsx', 'ods',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic',
    ];

    /**
     * What is wrong with an upload, as a message for the person who made it, or null when it is fine.
     * An empty field (no file chosen) is fine and yields null: the caller decides whether a document
     * was required.
     *
     * @param UploadedFile $file the uploaded file
     *
     * @return string|null the reason to reject it, or null when acceptable
     */
    public static function problem(UploadedFile $file): ?string
    {
        if (\UPLOAD_ERR_NO_FILE === $file->getError()) {
            return null;
        }
        if (!$file->isValid()) {
            return sprintf('No se pudo subir «%s».', $file->getClientOriginalName());
        }
        if ($file->getSize() > self::MAX_BYTES) {
            return sprintf('«%s» supera los %d MB.', $file->getClientOriginalName(), intdiv(self::MAX_BYTES, 1024 * 1024));
        }
        if (!\in_array(strtolower($file->getClientOriginalExtension()), self::ALLOWED_EXTENSIONS, true)) {
            return sprintf('«%s» tiene un tipo de archivo no admitido (usa PDF, Office, texto o imagen).', $file->getClientOriginalName());
        }

        return null;
    }

    /**
     * Whether the field actually carries a file (as opposed to being left empty).
     *
     * @param UploadedFile|null $file the field value
     *
     * @return bool true when there is a file to store
     */
    public static function isPresent(?UploadedFile $file): bool
    {
        return $file instanceof UploadedFile && \UPLOAD_ERR_NO_FILE !== $file->getError();
    }

    /**
     * The client filename to keep alongside the stored file, never empty.
     *
     * @param UploadedFile $file the uploaded file
     *
     * @return string the original name, or a generic one
     */
    public static function nameOf(UploadedFile $file): string
    {
        $name = $file->getClientOriginalName();

        return '' !== $name ? $name : 'documento';
    }
}
