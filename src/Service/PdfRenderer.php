<?php

declare(strict_types=1);

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Turns a Twig template into a PDF on the centre's letterhead: the documents the app hands out (the acta of
 * a meeting, the week's absences for RAICES) are laid out as ordinary templates extending
 * `pdf/base.html.twig` and handed to Dompdf, instead of being drawn coordinate by coordinate.
 *
 * Three deliberate settings, shared by every document:
 *  - remote resources are DISABLED, so a stray `<img src="http://…">` in somebody's text can never make the
 *    server fetch a URL (an SSRF through an acta would be a strange way to lose a firewall);
 *  - the default font is DejaVu Sans, which ships with Dompdf and actually has the accents and the ñ — the
 *    built-in Helvetica mangles them, and a document full of "Direcci?n" is not signable;
 *  - the letterhead logo travels as a `data:` URI built here ({@see logo()}) and not as a URL or a path.
 *    With remote resources off a URL would simply not load, and a filesystem path would depend on Dompdf's
 *    base path and chroot — a data URI is the one form that cannot resolve to something else.
 */
final readonly class PdfRenderer
{
    /** The centre's logo, relative to the public directory. The same file the app, the tab and the PWA use. */
    private const string LOGO_PATH = '/img/logo-ies.png';

    public function __construct(
        private Environment $twig,
        private string $publicDir,
    ) {
    }

    /**
     * Renders a template as an A4 PDF. The template receives `logo` on top of the given context.
     *
     * @param string               $template the template, extending `pdf/base.html.twig`
     * @param array<string, mixed> $context  the template's variables
     *
     * @return string the PDF contents
     */
    public function render(string $template, array $context = []): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($this->twig->render($template, ['logo' => $this->logo()] + $context), 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * The centre's logo as a `data:` URI, or null when the file is not there.
     *
     * Null is a real answer and the base template handles it: a document with a text-only letterhead is
     * still valid, and neither a deploy with a half-copied public directory nor a host without `ext-gd` must
     * stop the centre from producing it. Reading the file on every render is fine — one small PNG per press
     * of a button, and the OS page cache makes the second one free.
     *
     * **The gd check is not defensive noise.** Dompdf lists gd as "needed to process images", and what it
     * does WITHOUT it is not fail loudly: it draws its broken-image placeholder. A grey broken-image box in
     * the letterhead of an official acta is worse than no logo, and it is the kind of thing nobody notices
     * until a PDF is already signed and filed.
     *
     * Checked on the server (2026-08-05): the only PHP of cdmon is the phpfarm php84 build and gd is
     * compiled INTO it (`gd_info()` reports the bundled 2.1.0 with PNG and JPEG support), not loaded from an
     * `extension=` line — so CLI and web share it and there is nothing to enable.
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
}
