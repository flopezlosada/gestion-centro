<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Meeting;

/**
 * Turns a meeting into the PDF of its acta: the official letterhead, the convocatoria (day, time, place,
 * who convened, who keeps the minutes), the roll, the agenda and what was discussed and agreed.
 *
 * The rendering itself (Dompdf, its safe settings and the letterhead logo) is {@see PdfRenderer}'s; this
 * class knows only which template an acta uses and how its file is named.
 */
final readonly class MinutesPdfRenderer
{
    public function __construct(private PdfRenderer $pdf)
    {
    }

    /**
     * Renders the acta of a meeting as PDF bytes.
     *
     * @param Meeting $meeting the meeting to document
     *
     * @return string the PDF contents
     */
    public function render(Meeting $meeting): string
    {
        return $this->pdf->render('meeting/acta_pdf.html.twig', ['meeting' => $meeting]);
    }

    /**
     * The filename the acta is served with: dated, so a folder of downloaded actas sorts by itself and two
     * of them never collide.
     *
     * @param Meeting $meeting the meeting
     *
     * @return string the file name, e.g. "acta-2026-09-15-reunion-de-departamento.pdf"
     */
    public function fileNameFor(Meeting $meeting): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $this->withoutAccents($meeting->getTitle())));

        return \sprintf('acta-%s-%s.pdf', $meeting->getStartAt()->format('Y-m-d'), trim($slug, '-'));
    }

    /**
     * Folds Spanish accents so the file name is plain ASCII: a downloaded file travels through mail
     * clients, Windows shares and the centre's cloud, and a bare "acta-…-reunion.pdf" survives all three.
     *
     * A fixed map instead of iconv//TRANSLIT: the result of that depends on the C library of whatever host
     * the app runs on, and a file name is not worth a surprise.
     *
     * @param string $text the text to fold
     *
     * @return string the text without accents
     */
    private function withoutAccents(string $text): string
    {
        return strtr($text, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N', 'Ç' => 'C',
        ]);
    }
}
