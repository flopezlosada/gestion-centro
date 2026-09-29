<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Support\MeetingProse;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El texto con formato de una reunión tal como se guarda: con el saneador REAL de la configuración
 * (app.meeting_prose), que es lo que se quiere probar — un mock diría que sí a cualquier cosa.
 */
final class MeetingProseTest extends KernelTestCase
{
    private MeetingProse $prose;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->prose = self::getContainer()->get(MeetingProse::class);
    }

    /** Lo que da el editor se conserva: negrita, subrayado, listas y enlaces, que se abren aparte. */
    public function testTheEditorsFormattingIsKept(): void
    {
        $clean = (string) $this->prose->clean('<div><strong>Punto 1</strong>: <u>importante</u></div><ul><li>uno</li></ul><div><a href="https://meet.google.com/abc">la llamada</a></div>');

        self::assertStringContainsString('<strong>Punto 1</strong>', $clean);
        self::assertStringContainsString('<u>importante</u>', $clean);
        self::assertStringContainsString('<ul><li>uno</li></ul>', $clean);
        self::assertStringContainsString('href="https://meet.google.com/abc"', $clean);
        self::assertStringContainsString('rel="noopener noreferrer"', $clean);
        self::assertStringContainsString('target="_blank"', $clean);
    }

    /** Nada que ejecute o incruste llega a la base de datos: ni script, ni atributos on*, ni javascript:. */
    public function testNothingExecutableSurvives(): void
    {
        $clean = (string) $this->prose->clean('<div>Hola<script>alert(1)</script><img src=x onerror="alert(1)"><a href="javascript:alert(1)" onclick="x()">pulsa</a><iframe src="https://evil.test"></iframe></div>');

        self::assertStringNotContainsString('script', $clean);
        self::assertStringNotContainsString('onerror', $clean);
        self::assertStringNotContainsString('onclick', $clean);
        self::assertStringNotContainsString('javascript:', $clean);
        self::assertStringNotContainsString('iframe', $clean);
        self::assertStringNotContainsString('<img', $clean);
        self::assertStringContainsString('pulsa', $clean, 'el texto del enlace se queda aunque el enlace no');
    }

    /**
     * Texto plano (sin editor, sin JS) con sus saltos de línea: se guarda como lo habría escrito el editor,
     * escapado, para que no se pinte en una sola línea ni un «<» se lea como etiqueta.
     */
    public function testPlainTextKeepsItsLinesAndIsEscaped(): void
    {
        self::assertSame(
            '<div>1. Se aprueba la programación.<br />2. A &amp; B</div>',
            $this->prose->clean("1. Se aprueba la programación.\n2. A & B"),
        );
    }

    /** Un cuadro que el editor deja «vacío» no cuenta como escrito: el orden del día seguiría faltando. */
    public function testAnEmptyEditorIsNothing(): void
    {
        self::assertNull($this->prose->clean('<div><br></div>'));
        self::assertNull($this->prose->clean('   '));
        self::assertNull($this->prose->clean('<div>&nbsp;</div>'));
        self::assertNull($this->prose->clean(null));
    }
}
