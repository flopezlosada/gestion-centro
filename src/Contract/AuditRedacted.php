<?php

declare(strict_types=1);

namespace App\Contract;

/**
 * Marks a property of an {@see Auditable} entity whose CONTENT must not be kept in the activity trail:
 * the trail still records that it changed, and whether it was set or cleared, but each value is
 * replaced by {@see MARK}. For free text that may hold personal data — the text of an acta, an agenda —
 * which must live in one place only, and not again, word for word, in every audit row and every backup.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class AuditRedacted
{
    /** What the trail keeps instead of a value that was there. */
    public const string MARK = '(contenido no guardado)';
}
