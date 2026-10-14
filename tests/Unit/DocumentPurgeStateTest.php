<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\CopyRequest;
use App\Entity\GuardiaCover;
use PHPUnit\Framework\TestCase;

/**
 * A guardia and a copy order either keep their document or say it was purged, never both: purging drops
 * path and name and stamps when, and a new document clears the stamp.
 */
final class DocumentPurgeStateTest extends TestCase
{
    public function testPurgarLaTareaDeUnaGuardiaDevuelveElFicheroYDejaLaMarca(): void
    {
        $at = new \DateTimeImmutable('2026-10-14 03:00');
        $cover = (new GuardiaCover())->setTaskDocumentPath('guardia-tasks/a.pdf')->setTaskDocumentName('Examen de Lucía.pdf');

        self::assertSame('guardia-tasks/a.pdf', $cover->purgeTaskDocument($at));
        self::assertNull($cover->getTaskDocumentPath());
        self::assertNull($cover->getTaskDocumentName());
        self::assertSame($at, $cover->getTaskDocumentPurgedAt());
        self::assertTrue($cover->hasTask(), 'tuvo tarea aunque ya no tenga el fichero');
    }

    public function testUnaGuardiaSinDocumentoNoSeMarca(): void
    {
        $cover = (new GuardiaCover())->setTaskDescription('Página 40');

        self::assertNull($cover->purgeTaskDocument(new \DateTimeImmutable('2026-10-14 03:00')));
        self::assertNull($cover->getTaskDocumentPurgedAt(), 'no hubo documento que borrar');
    }

    public function testSubirOtroDocumentoAUnaGuardiaQuitaLaMarca(): void
    {
        $cover = (new GuardiaCover())->setTaskDocumentPath('guardia-tasks/a.pdf');
        $cover->purgeTaskDocument(new \DateTimeImmutable('2026-10-14 03:00'));

        $cover->setTaskDocumentPath('guardia-tasks/b.pdf');

        self::assertNull($cover->getTaskDocumentPurgedAt());
        self::assertSame('guardia-tasks/b.pdf', $cover->getTaskDocumentPath());
    }

    public function testPurgarElDocumentoDeUnEncargoDevuelveElFicheroYDejaLaMarca(): void
    {
        $at = new \DateTimeImmutable('2026-10-14 03:00');
        $order = (new CopyRequest())->setDocumentPath('copy-requests/a.pdf')->setDocumentName('Examen de Lucía.pdf');

        self::assertSame('copy-requests/a.pdf', $order->purgeDocument($at));
        self::assertFalse($order->hasDocument());
        self::assertNull($order->getDocumentName());
        self::assertSame($at, $order->getDocumentPurgedAt());

        $order->setDocumentPath('copy-requests/b.pdf');
        self::assertNull($order->getDocumentPurgedAt());
    }

    public function testUnEncargoSoloDeTextoNoSeMarca(): void
    {
        self::assertNull((new CopyRequest())->purgeDocument(new \DateTimeImmutable('2026-10-14 03:00')));
    }
}
