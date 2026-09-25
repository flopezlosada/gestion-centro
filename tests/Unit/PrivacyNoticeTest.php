<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\PrivacyNotice;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * How a version's text is split for reading: the summary as label/value rows only when every line is
 * written that way, and paragraphs at blank lines.
 */
final class PrivacyNoticeTest extends TestCase
{
    /**
     * "Etiqueta: texto" on every line becomes rows; a colon later in the text (a URL) stays in the value.
     */
    public function testASummaryWrittenAsLabelsBecomesRows(): void
    {
        $notice = $this->notice("Responsable: Consejería de Educación\n\nMás información: https://example.org/datos\n");

        self::assertSame([
            ['label' => 'Responsable', 'text' => 'Consejería de Educación'],
            ['label' => 'Más información', 'text' => 'https://example.org/datos'],
        ], $notice->summaryRows());
    }

    /**
     * One line of prose is enough to show the whole summary as prose, not as a half-broken table.
     */
    public function testASummaryWithAnyProseLineStaysProse(): void
    {
        $notice = $this->notice("Responsable: la Consejería\nEl centro usa tus datos para organizar el trabajo.");

        self::assertNull($notice->summaryRows());
        self::assertCount(1, $notice->summaryParagraphs());
    }

    /**
     * A single labelled line is a sentence, not a table.
     */
    public function testASingleLabelledLineIsNotATable(): void
    {
        self::assertNull($this->notice('Nota: todo esto es provisional.')->summaryRows());
    }

    /**
     * Paragraphs break at blank lines (also with stray spaces or Windows line ends), never inside one.
     */
    public function testTheBodySplitsIntoParagraphsAtBlankLines(): void
    {
        $notice = new PrivacyNotice('Resumen', "Primero\nsigue el primero\r\n  \r\nSegundo\n\n\nTercero", new User(), new \DateTimeImmutable());

        self::assertSame(["Primero\nsigue el primero", 'Segundo', 'Tercero'], $notice->bodyParagraphs());
    }

    /**
     * @param string $summary the summary text
     *
     * @return PrivacyNotice a version with that summary
     */
    private function notice(string $summary): PrivacyNotice
    {
        return new PrivacyNotice($summary, 'Completa', new User(), new \DateTimeImmutable());
    }
}
