<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Cloud\ProseToOdt;
use PHPUnit\Framework\TestCase;

/**
 * The text of an acta written in the app, as ODF paragraphs for its .odt in the cloud: the formatting the
 * editor allows survives, and nothing in the text can reach the document as markup.
 */
final class ProseToOdtTest extends TestCase
{
    public function testNothingToReadIsNothing(): void
    {
        self::assertNull(ProseToOdt::convert(null));
        self::assertNull(ProseToOdt::convert(''));
        self::assertNull(ProseToOdt::convert('<div><br></div>'));
    }

    public function testParagraphsKeepTheirFormattingAndLineBreaks(): void
    {
        self::assertSame(
            '<text:p text:style-name="Body">Se acuerda <text:span text:style-name="Strong">todo</text:span> y <text:span text:style-name="Em">nada</text:span><text:line-break/>por unanimidad</text:p>'
            .'<text:p text:style-name="Body">Segundo párrafo</text:p>',
            ProseToOdt::convert('<div>Se acuerda <strong>todo</strong> y <em>nada</em><br>por unanimidad<br></div><div>Segundo párrafo</div>'),
        );
    }

    public function testListsHeadingsAndLinks(): void
    {
        $odt = (string) ProseToOdt::convert('<h1>Puntos</h1><ol><li>Uno</li><li>Dos</li></ol><ul><li>Viñeta</li></ul><div><a href="https://educa.madrid.org">enlace</a></div>');

        self::assertStringContainsString('<text:p text:style-name="ProseHeading">Puntos</text:p>', $odt);
        self::assertStringContainsString('<text:list text:style-name="Numbers"><text:list-item><text:p text:style-name="ListBody">Uno</text:p></text:list-item>', $odt);
        self::assertStringContainsString('<text:list text:style-name="Bullets">', $odt);
        self::assertStringContainsString('<text:a xlink:type="simple" xlink:href="https://educa.madrid.org">enlace</text:a>', $odt);
    }

    public function testTextIsEscapedAndUnknownMarkupLosesOnlyItsFormatting(): void
    {
        $odt = (string) ProseToOdt::convert('<div>5 &lt; 6 &amp; "comillas" <script>alerta()</script><a href="javascript:alert(1)">no</a></div>');

        self::assertSame('<text:p text:style-name="Body">5 &lt; 6 &amp; &quot;comillas&quot; alerta()no</text:p>', $odt);
        $xml = new \DOMDocument();
        self::assertTrue($xml->loadXML('<r xmlns:text="urn:t" xmlns:xlink="urn:x">'.$odt.'</r>'), 'XML bien formado');
    }

    public function testInlineTextAtTheTopIsAParagraphToo(): void
    {
        self::assertSame('<text:p text:style-name="Body">Texto suelto</text:p>', ProseToOdt::convert('Texto suelto'));
    }
}
