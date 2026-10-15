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

    public function testPurgarLosDocumentosDeUnEncargoDevuelveSusFicherosYDejaLaMarca(): void
    {
        $at = new \DateTimeImmutable('2026-10-14 03:00');
        $order = (new CopyRequest())
            ->addLine(25, 'copy-requests/a.pdf', 'Examen de Lucía.pdf')
            ->addLine(1, 'copy-requests/b.pdf', 'Soluciones.pdf');

        self::assertSame(['copy-requests/a.pdf', 'copy-requests/b.pdf'], $order->purgeDocuments($at));
        self::assertFalse($order->hasDocument());
        self::assertSame([null, null], array_map(static fn ($l) => $l->getName(), $order->getLines()));
        self::assertSame($at, $order->getDocumentPurgedAt());
        // Las copias no son datos personales: se quedan para el listado.
        self::assertSame(26, $order->getTotalCopies());
    }

    public function testUnEncargoSoloDeTextoNoSeMarca(): void
    {
        $order = (new CopyRequest())->addLine(10);

        self::assertSame([], $order->purgeDocuments(new \DateTimeImmutable('2026-10-14 03:00')));
        self::assertNull($order->getDocumentPurgedAt());
    }
}
