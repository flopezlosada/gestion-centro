<?php

declare(strict_types=1);

namespace App\Service\Cloud;

use App\Entity\Meeting;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Builds the TEMPLATE of an acta as an OpenDocument text file (.odt): the centre's letterhead (logo,
 * header and page-numbered footer), the heading, the roll, the agenda, one empty heading per agenda item
 * under "Desarrollo" for the secretary to fill in — in EducaMadrid's editor, not here — and the signatures.
 *
 * Built by hand (a zip with five entries) rather than with a library or LibreOffice: the server has
 * neither, and an .odt is a small, documented format. Only what the template needs is in it.
 */
final class MinutesOdtRenderer
{
    public function __construct(
        private Environment $twig,
        #[Autowire('%kernel.project_dir%/public/img/logo-ies.png')]
        private string $logoPath,
    ) {
    }

    /**
     * @param Meeting $meeting the meeting whose acta it is
     *
     * @return string the .odt file's bytes
     */
    public function render(Meeting $meeting): string
    {
        $content = $this->twig->render('meeting/acta_cloud/content.xml.twig', [
            'meeting' => $meeting,
            'agendaItems' => self::agendaItems($meeting->getAgenda()),
        ]);
        // La cabecera y el pie de cada página dicen de qué reunión es el acta: también necesitan la reunión.
        $styles = $this->twig->render('meeting/acta_cloud/styles.xml.twig', ['meeting' => $meeting]);

        $path = tempnam(sys_get_temp_dir(), 'acta');
        try {
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::OVERWRITE);
            // El primer fichero, y SIN comprimir: así reconoce un lector un .odt por sus primeros bytes.
            $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
            $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
            $zip->addFromString('META-INF/manifest.xml', $this->twig->render('meeting/acta_cloud/manifest.xml.twig'));
            $zip->addFromString('styles.xml', $styles);
            $zip->addFromString('content.xml', $content);
            $zip->addFile($this->logoPath, 'Pictures/logo.png');
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * The agenda's items, one per line or list item of its rich text, without the numbering somebody
     * may have typed in (the template numbers them itself).
     *
     * @param string|null $agendaHtml the agenda as the editor stored it
     *
     * @return list<string> the items, in order
     */
    public static function agendaItems(?string $agendaHtml): array
    {
        if (null === $agendaHtml || '' === trim($agendaHtml)) {
            return [];
        }
        $text = preg_replace(['#<br\s*/?>#i', '#</(div|p|li|h\d)>#i'], "\n", $agendaHtml) ?? '';
        $lines = explode("\n", html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5));

        return array_values(array_filter(
            array_map(static fn (string $l): string => trim(preg_replace('/^\s*\d+\s*[.)º]\s*/u', '', $l) ?? ''), $lines),
            static fn (string $l): bool => '' !== $l,
        ));
    }
}
