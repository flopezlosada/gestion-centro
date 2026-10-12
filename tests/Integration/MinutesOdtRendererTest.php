<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Meeting;
use App\Entity\User;
use App\Service\Cloud\MinutesOdtRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The template of a cloud acta, built by hand as an .odt: a file the cloud's editor has to recognise and
 * open (not offer to download), and whose text comes from what people typed — a title or an agenda item
 * with a "<" or an "&" must not break the XML.
 */
final class MinutesOdtRendererTest extends KernelTestCase
{
    private function renderer(): MinutesOdtRenderer
    {
        self::bootKernel();

        return new MinutesOdtRenderer(self::getContainer()->get(Environment::class), self::getContainer()->getParameter('kernel.project_dir').'/public/img/logo-ies.png');
    }

    /**
     * Opens the .odt bytes as a zip.
     *
     * @return array{zip: \ZipArchive, path: string}
     */
    private static function open(string $bytes): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'odt');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));

        return ['zip' => $zip, 'path' => $path];
    }

    public function testItIsAnOdtTheEditorRecognisesWithTextThatCannotBreakIt(): void
    {
        $convener = (new User())->setFullName('Natalia Ruiz')->setEmail('natalia.x@educa.madrid.org');
        $meeting = (new Meeting($convener, 'Lengua & Literatura <1º ESO>', new \DateTimeImmutable('2026-09-25 10:15')))
            ->setAgenda('<ol><li>Programación & criterios</li><li>Ruegos <y> preguntas</li></ol>')
            ->addAttendee($convener);

        ['zip' => $zip, 'path' => $path] = self::open($this->renderer()->render($meeting));
        try {
            // Un lector reconoce el .odt por sus primeros bytes: «mimetype» primero y sin comprimir.
            self::assertSame('mimetype', $zip->getNameIndex(0));
            self::assertSame(\ZipArchive::CM_STORE, $zip->statIndex(0)['comp_method']);
            self::assertSame('application/vnd.oasis.opendocument.text', $zip->getFromName('mimetype'));
            self::assertNotFalse($zip->getFromName('Pictures/logo.png'), 'el logo que la cabecera enlaza va dentro');

            foreach (['content.xml', 'styles.xml', 'META-INF/manifest.xml'] as $entry) {
                $xml = new \DOMDocument();
                self::assertTrue(@$xml->loadXML((string) $zip->getFromName($entry)), $entry.' es XML válido');
            }
            $content = (string) $zip->getFromName('content.xml');
            self::assertStringContainsString('Lengua &amp; Literatura &lt;1º ESO&gt;', $content);
            self::assertStringContainsString('Programación &amp; criterios', $content);
            self::assertStringContainsString('Natalia Ruiz', $content);
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function testTheAgendaBecomesItsItemsWithoutTheNumbersTypedIn(): void
    {
        self::assertSame([], MinutesOdtRenderer::agendaItems(null));
        self::assertSame([], MinutesOdtRenderer::agendaItems('<p> </p>'));
        self::assertSame(
            ['Lectura del acta anterior', 'Programación', 'Ruegos y preguntas'],
            MinutesOdtRenderer::agendaItems('<p>1. Lectura del acta anterior</p><div>2) Programación<br>3º Ruegos y preguntas</div>'),
        );
        self::assertSame(['Salidas & excursiones'], MinutesOdtRenderer::agendaItems('<ul><li>Salidas &amp; excursiones</li></ul>'));
    }

    public function testWhoeverConvenesSignsTheVistoBuenoAndSignsOnceWhenTheyAlsoKeepTheMinutes(): void
    {
        $convener = (new User())->setFullName('Mercedes Alende')->setEmail('mercedes.a@educa.madrid.org');
        $secretary = (new User())->setFullName('Natalia Ruiz')->setEmail('natalia.x@educa.madrid.org');
        $meeting = new Meeting($convener, 'CCP', new \DateTimeImmutable('2026-09-29 10:15'));

        $alone = $this->content($meeting);
        self::assertStringContainsString('Convoca y levanta el acta', $alone);
        self::assertSame(1, substr_count($alone, 'text:style-name="SignName"'), 'una persona, una firma');

        $meeting->setMinutesTakenBy($secretary);
        $two = $this->content($meeting);
        self::assertStringContainsString('Levanta el acta</text:p>', $two);
        self::assertStringContainsString('Vº Bº · Convoca', $two);
        self::assertSame(2, substr_count($two, 'text:style-name="SignName"'));
    }

    private function content(Meeting $meeting): string
    {
        ['zip' => $zip, 'path' => $path] = self::open($this->renderer()->render($meeting));
        try {
            return (string) $zip->getFromName('content.xml');
        } finally {
            $zip->close();
            unlink($path);
        }
    }
}
