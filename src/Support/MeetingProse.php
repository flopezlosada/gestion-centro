<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * The formatted text of a meeting — its agenda, what was discussed and what was agreed — as it is stored:
 * HTML written by the editor, cleaned on the way in by the {@code app.meeting_prose} sanitizer, so only
 * formatting and http(s)/mailto links ever reach the database. The templates run the same sanitizer
 * again on the way out ({@code sanitize_html}), so a row written by any other path cannot slip past it.
 *
 * A box the editor leaves "empty" is not: Trix keeps a {@code <div><br></div>}. Stored as is, it would
 * count as written — the agenda would stop showing "falta el orden del día" — so text with nothing to
 * read becomes null.
 */
final readonly class MeetingProse
{
    public function __construct(
        #[Target('app.meeting_prose')]
        private HtmlSanitizerInterface $sanitizer,
    ) {
    }

    /**
     * Cleans what the editor sent into what may be stored.
     *
     * @param string|null $html the submitted HTML
     *
     * @return string|null the sanitized HTML, or null when there is nothing to read in it
     */
    public function clean(?string $html): ?string
    {
        if (null === $html) {
            return null;
        }
        // Sin editor (sin JS) llega texto plano: sus saltos de línea no son etiquetas, y pintado como HTML
        // se quedaría en una sola línea. Se pasa a la misma forma que escribe el editor.
        if (strip_tags($html) === $html) {
            $html = self::fromPlainText($html);
        }
        $clean = trim($this->sanitizer->sanitize($html));

        return '' === trim(html_entity_decode(strip_tags($clean), \ENT_QUOTES | \ENT_HTML5), " \t\n\r\0\x0B\u{A0}") ? null : $clean;
    }

    /**
     * Plain text as the editor would have written it: escaped, its line breaks kept as <br>.
     *
     * @param string $text the plain text
     *
     * @return string the equivalent HTML
     */
    private static function fromPlainText(string $text): string
    {
        $lines = preg_split('/\R/u', trim($text)) ?: [];

        return '<div>'.implode('<br>', array_map(static fn (string $line): string => htmlspecialchars($line, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), $lines)).'</div>';
    }
}
