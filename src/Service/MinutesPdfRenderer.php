<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Meeting;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Turns a meeting into the PDF of its acta: the official letterhead, the convocatoria (day, time, place,
 * who convened, who keeps the minutes), the roll, the agenda and what was discussed and agreed.
 *
 * The layout is a Twig template rendered to HTML and handed to Dompdf, so the document is edited like any
 * other screen of the app instead of being drawn coordinate by coordinate — and the same template can serve
 * a print view later if it is ever needed.
 *
 * Three deliberate settings:
 *  - remote resources are DISABLED, so a stray `<img src="http://…">` in somebody's text can never make the
 *    server fetch a URL (an SSRF through an acta would be a strange way to lose a firewall);
 *  - the default font is DejaVu Sans, which ships with Dompdf and actually has the accents and the ñ — the
 *    built-in Helvetica mangles them, and an acta full of "Direcci?n" is not signable;
 *  - the letterhead logo travels as a `data:` URI built here ({@see logo()}) and not as a URL or a path.
 *    With remote resources off a URL would simply not load, and a filesystem path would depend on Dompdf's
 *    base path and chroot — a data URI is the one form that cannot resolve to something else.
 */
final readonly class MinutesPdfRenderer
{
    /** The centre's logo, relative to the public directory. The same file the app, the tab and the PWA use. */
    private const string LOGO_PATH = '/img/logo-ies.png';

    public function __construct(
        private Environment $twig,
        private string $publicDir,
    ) {
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
        $dompdf->loadHtml($this->twig->render('meeting/acta_pdf.html.twig', [
            'meeting' => $meeting,
            'logo' => $this->logo(),
        ]), 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * The centre's logo as a `data:` URI, or null when the file is not there.
     *
     * Null is a real answer and the template handles it: an acta is a valid document with a text-only
     * letterhead, and neither a deploy with a half-copied public directory nor a host without `ext-gd` must
     * stop the centre from producing its actas. Reading the file on every render is fine — one small PNG per
     * press of a button, and the OS page cache makes the second one free.
     *
     * **The gd check is not defensive noise.** Dompdf lists gd as "needed to process images", and what it
     * does WITHOUT it is not fail loudly: it draws its broken-image placeholder. A grey broken-image box in
     * the letterhead of an official acta is worse than no logo, and it is the kind of thing nobody notices
     * until a PDF is already signed and filed.
     *
     * Checked on the server (2026-08-05): the only PHP of cdmon is the phpfarm php84 build and gd is
     * compiled INTO it (`gd_info()` reports the bundled 2.1.0 with PNG and JPEG support), not loaded from an
     * `extension=` line — so CLI and web share it and there is nothing to enable. The header of the acta
     * template used to give this exact extension as the reason for having no logo at all.
     *
     * @return string|null the data URI, or null when there is no logo to embed
     */
    private function logo(): ?string
    {
        if (!\extension_loaded('gd')) {
            return null;
        }

        $path = $this->publicDir.self::LOGO_PATH;
        if (!is_file($path)) {
            return null;
        }

        $bytes = file_get_contents($path);

        return false !== $bytes ? 'data:image/png;base64,'.base64_encode($bytes) : null;
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
