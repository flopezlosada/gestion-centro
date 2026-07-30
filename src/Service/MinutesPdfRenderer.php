<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Meeting;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Turns a meeting into the PDF of its acta: the convocatoria (day, time, place, who convened, who keeps the
 * minutes), the roll, the agenda and what was discussed and agreed.
 *
 * The layout is a Twig template rendered to HTML and handed to Dompdf, so the document is edited like any
 * other screen of the app instead of being drawn coordinate by coordinate — and the same template can serve
 * a print view later if it is ever needed.
 *
 * Two deliberate settings:
 *  - remote resources are DISABLED, so a stray `<img src="http://…">` in somebody's text can never make the
 *    server fetch a URL (an SSRF through an acta would be a strange way to lose a firewall);
 *  - the default font is DejaVu Sans, which ships with Dompdf and actually has the accents and the ñ — the
 *    built-in Helvetica mangles them, and an acta full of "Direcci?n" is not signable.
 */
final readonly class MinutesPdfRenderer
{
    public function __construct(private Environment $twig)
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
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($this->twig->render('meeting/acta_pdf.html.twig', ['meeting' => $meeting]), 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
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
