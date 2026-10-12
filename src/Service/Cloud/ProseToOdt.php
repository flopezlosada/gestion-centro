<?php

declare(strict_types=1);

namespace App\Service\Cloud;

/**
 * The formatted text of a meeting, as the app stored it (HTML cleaned by the {@code app.meeting_prose}
 * sanitizer: paragraphs, line breaks, bold, italics, underline, strike, a heading, quotes, code, lists and
 * links), turned into OpenDocument paragraphs for the acta's .odt — what an acta written in the app
 * carries with it when it moves to the cloud.
 *
 * Every text node is escaped here and every element maps to a fixed ODF construct, so nothing of the input
 * reaches the document as markup. An element outside that list keeps its text and loses its formatting.
 * The paragraph, span and list styles it names are defined by the acta's templates.
 */
final class ProseToOdt
{
    /** Inline elements and the text style each one becomes. */
    private const array SPANS = [
        'strong' => 'Strong', 'b' => 'Strong',
        'em' => 'Em', 'i' => 'Em',
        'u' => 'Underline',
        's' => 'Strike', 'del' => 'Strike',
    ];

    /** Block elements that are a paragraph of their own, and its style. */
    private const array BLOCKS = [
        'p' => 'Body', 'div' => 'Body', 'h1' => 'ProseHeading', 'blockquote' => 'Quote', 'pre' => 'Body',
    ];

    /**
     * @param string|null $html the stored HTML
     *
     * @return string|null the ODF paragraphs, or null when there is nothing to read
     */
    public static function convert(?string $html): ?string
    {
        if (null === $html || '' === trim($html)) {
            return null;
        }

        $document = new \DOMDocument();
        // El prefijo de codificación hace que libxml lea UTF-8 y no Latin-1; el envoltorio da un nodo raíz.
        $document->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', \LIBXML_NOERROR | \LIBXML_NOWARNING | \LIBXML_NONET);
        $body = $document->getElementsByTagName('body')->item(0);
        if (null === $body) {
            return null;
        }

        $odt = self::blocks($body, 'Body');

        return '' === $odt ? null : $odt;
    }

    /**
     * The children of a node as ODF paragraphs: blocks become paragraphs and lists, and runs of inline
     * content between them are gathered into a paragraph of the given style.
     */
    private static function blocks(\DOMNode $node, string $style): string
    {
        $out = '';
        $run = '';
        $flush = static function () use (&$out, &$run, $style): void {
            if ('' !== trim(strip_tags(str_replace('<text:line-break/>', ' ', $run)))) {
                $out .= '<text:p text:style-name="'.$style.'">'.self::trimBreaks($run).'</text:p>';
            }
            $run = '';
        };

        foreach ($node->childNodes as $child) {
            $name = $child instanceof \DOMElement ? strtolower($child->tagName) : null;
            if (null !== $name && isset(self::BLOCKS[$name])) {
                $flush();
                // Un bloque que contiene otros bloques (div dentro de div) se aplana; si no, es un párrafo.
                $out .= self::hasBlockChildren($child) ? self::blocks($child, self::BLOCKS[$name]) : self::paragraph($child, self::BLOCKS[$name]);
            } elseif ('ul' === $name || 'ol' === $name) {
                $flush();
                $out .= self::list($child, 'ol' === $name ? 'Numbers' : 'Bullets');
            } else {
                $run .= self::inline($child);
            }
        }
        $flush();

        return $out;
    }

    private static function paragraph(\DOMNode $node, string $style): string
    {
        $inline = self::trimBreaks(self::inlineChildren($node));

        return '' === trim(strip_tags(str_replace('<text:line-break/>', ' ', $inline))) ? '' : '<text:p text:style-name="'.$style.'">'.$inline.'</text:p>';
    }

    private static function list(\DOMNode $node, string $style): string
    {
        $items = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && 'li' === strtolower($child->tagName)) {
                $content = self::blocks($child, 'ListBody');
                $items .= '<text:list-item>'.('' === $content ? '<text:p text:style-name="ListBody"/>' : $content).'</text:list-item>';
            }
        }

        return '' === $items ? '' : '<text:list text:style-name="'.$style.'">'.$items.'</text:list>';
    }

    private static function inline(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return htmlspecialchars($node->data, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        $name = strtolower($node->tagName);
        if ('br' === $name) {
            return '<text:line-break/>';
        }
        $inner = self::inlineChildren($node);
        if (isset(self::SPANS[$name])) {
            return '<text:span text:style-name="'.self::SPANS[$name].'">'.$inner.'</text:span>';
        }
        $href = 'a' === $name ? $node->getAttribute('href') : '';
        // El sanitizador ya solo deja http(s) y mailto; se vuelve a mirar porque esto escribe un documento.
        if ('' !== $href && 1 === preg_match('#^(https?:|mailto:)#i', $href)) {
            return '<text:a xlink:type="simple" xlink:href="'.htmlspecialchars($href, \ENT_XML1 | \ENT_QUOTES, 'UTF-8').'">'.$inner.'</text:a>';
        }

        return $inner;
    }

    private static function inlineChildren(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= self::inline($child);
        }

        return $out;
    }

    private static function hasBlockChildren(\DOMNode $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && (isset(self::BLOCKS[strtolower($child->tagName)]) || \in_array(strtolower($child->tagName), ['ul', 'ol'], true))) {
                return true;
            }
        }

        return false;
    }

    /** The editor ends many paragraphs with a <br>: a break at either end is not content. */
    private static function trimBreaks(string $odt): string
    {
        return (string) preg_replace(['#^(\s|<text:line-break/>)+#', '#(\s|<text:line-break/>)+$#'], '', $odt);
    }
}
