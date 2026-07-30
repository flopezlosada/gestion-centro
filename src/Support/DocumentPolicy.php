<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * What the application accepts as an uploaded document, in ONE place: the size ceiling, the allowed
 * extensions and the name to store. Shared by every feature that takes a file (the task a teacher leaves
 * for their guardia, the minutes of a meeting), so the policy cannot drift between them — and so raising
 * the ceiling is one edit, not a hunt.
 *
 * Defence in depth on top of the storage itself: files live outside the web root, are renamed to a random
 * UUID ({@see \App\Service\FileUploader}) and are always served as a download, so the extension check is
 * a filter of what makes sense to keep, not the only thing between an upload and execution.
 */
final class DocumentPolicy
{
    /** Size ceiling for an uploaded document. */
    public const int MAX_BYTES = 10 * 1024 * 1024;

    /** Accepted extensions: office documents, plain text and images (a scan or a phone photo). */
    public const array ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt',
        'ppt', 'pptx', 'xls', 'xlsx', 'ods',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic',
    ];

    /**
     * Why the file cannot be accepted, as a sentence ready to show, or null when it is fine. Returning
     * the REASON (instead of a bare bool) is what lets every caller tell the user what to do about it;
     * each one decides whether that is a warning it carries on from or an error that stops the action.
     *
     * An empty field (no file chosen) is NOT this method's business: callers check
     * {@see \UPLOAD_ERR_NO_FILE} first, because "no ha subido nada" usually means "no quería subir nada".
     *
     * @param UploadedFile $file the uploaded file
     *
     * @return string|null the human reason it was rejected, or null when acceptable
     */
    public static function rejectionOf(UploadedFile $file): ?string
    {
        if (!$file->isValid()) {
            return \sprintf('No se pudo subir «%s».', $file->getClientOriginalName());
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return \sprintf('«%s» supera los %d MB.', $file->getClientOriginalName(), intdiv(self::MAX_BYTES, 1024 * 1024));
        }

        if (!\in_array(strtolower($file->getClientOriginalExtension()), self::ALLOWED_EXTENSIONS, true)) {
            return \sprintf('«%s» tiene un tipo de archivo no admitido (usa PDF, Office, texto o imagen).', $file->getClientOriginalName());
        }

        return null;
    }

    /**
     * The name to keep for the download, from the client filename. Never empty: a nameless file would be
     * served as an unnamed attachment.
     *
     * @param UploadedFile $file the uploaded file
     *
     * @return string the filename to store
     */
    public static function nameOf(UploadedFile $file): string
    {
        $name = $file->getClientOriginalName();

        return '' !== $name ? $name : 'documento';
    }
}
