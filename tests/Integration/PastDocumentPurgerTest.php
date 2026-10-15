<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\CopyRequest;
use App\Entity\GuardiaCover;
use App\Entity\User;
use App\Guardia\PastDocumentPurger;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A copy order's document lives for its day only (the copy room gets it by e-mail); a guardia's task
 * document lives for its school year. Then the file and its name go, the record stays marked as purged,
 * and whatever is still in time is left alone.
 */
final class PastDocumentPurgerTest extends KernelTestCase
{
    private const string NOW = '2026-10-14 03:00';

    private EntityManagerInterface $em;
    private FileUploader $uploader;
    private PastDocumentPurger $purger;
    private User $absent;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->uploader = self::getContainer()->get(FileUploader::class);
        $this->purger = self::getContainer()->get(PastDocumentPurger::class);
        $this->absent = (new User())->setFullName('Ausente')->setEmail('ausente-'.uniqid().'@centro.test');
        $this->em->persist($this->absent);
    }

    /**
     * A guardia on that day, with a task document stored for real.
     *
     * @param string $day the guardia's day, Y-m-d
     */
    private function coverWithDocument(string $day, int $slot = 1): GuardiaCover
    {
        $date = new \DateTimeImmutable($day);
        $absence = $this->em->getRepository(Absence::class)->findOneBy(['absentTeacher' => $this->absent, 'date' => $date])
            ?? (new Absence())->setAbsentTeacher($this->absent)->setDate($date);
        $this->em->persist($absence);
        $cover = (new GuardiaCover())
            ->setAbsence($absence)
            ->setDate($date)
            ->setSlotIndex($slot)
            ->setAbsentTeacher($this->absent)
            ->setTaskDocumentPath($this->uploader->store('%PDF-1.4 tarea', 'guardia-tasks', 'pdf'))
            ->setTaskDocumentName('Examen adaptado de Lucía.pdf')
            ->setTaskDescription('Página 40');
        $this->em->persist($cover);
        $this->em->flush();

        return $cover;
    }

    /**
     * A copy order with its own stored documents (one unless told otherwise), placed at that moment, for
     * that guardia or for none, and sent at once unless told otherwise.
     */
    private function order(string $placedAt, ?GuardiaCover $cover = null, bool $sent = true, int $files = 1): CopyRequest
    {
        $order = (new CopyRequest())
            ->setCover($cover)
            ->setContext('4º ESO · E4C')
            ->setRecipient('fotocopias@centro.test');
        for ($i = 1; $i <= $files; ++$i) {
            $order->addLine(25, $this->uploader->store('%PDF-1.4 copia', 'copy-requests', 'pdf'), sprintf('Examen adaptado de Lucía %d.pdf', $i));
        }
        (new \ReflectionProperty(CopyRequest::class, 'requestedAt'))->setValue($order, new \DateTimeImmutable($placedAt));
        if ($sent) {
            $order->markSent(new \DateTimeImmutable($placedAt));
        }
        $this->em->persist($order);
        $this->em->flush();

        return $order;
    }

    private function isStored(string $path): bool
    {
        return is_file($this->uploader->absolutePath($path));
    }

    public function testBorraElDocumentoDeUnaGuardiaDeUnCursoPasadoYConservaQueLoHubo(): void
    {
        $cover = $this->coverWithDocument('2026-06-19');
        $path = (string) $cover->getTaskDocumentPath();

        $purged = $this->purger->purge(new \DateTimeImmutable(self::NOW));

        self::assertSame(['covers' => 1, 'orders' => 0, 'unremoved' => []], $purged);
        self::assertFalse($this->isStored($path), 'el fichero sale del disco');
        $this->em->clear();
        $reloaded = $this->em->getRepository(GuardiaCover::class)->find($cover->getId());
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getTaskDocumentPath());
        self::assertNull($reloaded->getTaskDocumentName(), 'el nombre también puede nombrar a un alumno');
        self::assertEquals(new \DateTimeImmutable(self::NOW), $reloaded->getTaskDocumentPurgedAt());
        self::assertSame('Página 40', $reloaded->getTaskDescription(), 'la descripción no es el documento');
        self::assertTrue($reloaded->hasTask(), 'una guardia pasada que tuvo documento no se lee como «sin tarea»');
    }

    /**
     * The course runs from 1 September: everything from then on, past days included, is kept.
     */
    public function testConservaTodasLasGuardiasDelCursoEnMarcha(): void
    {
        $firstDay = $this->coverWithDocument('2026-09-01', 1);
        $yesterday = $this->coverWithDocument('2026-10-13', 2);
        $tomorrow = $this->coverWithDocument('2026-10-15', 3);

        $purged = $this->purger->purge(new \DateTimeImmutable(self::NOW));

        self::assertSame(['covers' => 0, 'orders' => 0, 'unremoved' => []], $purged);
        foreach ([$firstDay, $yesterday, $tomorrow] as $cover) {
            self::assertTrue($this->isStored((string) $cover->getTaskDocumentPath()));
            self::assertNull($cover->getTaskDocumentPurgedAt());
        }
    }

    public function testElUltimoDiaDelCursoAnteriorYaSeBorra(): void
    {
        $lastDay = $this->coverWithDocument('2026-08-31');

        self::assertSame(['covers' => 1, 'orders' => 0, 'unremoved' => []], $this->purger->purge(new \DateTimeImmutable(self::NOW)));
        self::assertNotNull($lastDay->getTaskDocumentPurgedAt());
    }

    /**
     * An order counts by its guardia's day, not by when it was placed: copies asked yesterday for next
     * week's guardia are still needed. The guardias themselves, this course's, keep their documents.
     */
    public function testUnEncargoDeGuardiaCuentaPorElDiaDeLaGuardia(): void
    {
        $pastGuardia = $this->order('2026-10-12 09:00', $this->coverWithDocument('2026-10-13', 1));
        $futureGuardia = $this->order('2026-10-13 09:00', $this->coverWithDocument('2026-10-20', 2));

        $purged = $this->purger->purge(new \DateTimeImmutable(self::NOW));

        self::assertSame(['covers' => 0, 'orders' => 1, 'unremoved' => []], $purged);
        self::assertNull($pastGuardia->getLines()[0]->getPath());
        self::assertNull($pastGuardia->getLines()[0]->getName());
        self::assertNotNull($pastGuardia->getDocumentPurgedAt());
        self::assertNotNull($futureGuardia->getLines()[0]->getPath(), 'la guardia aún no ha llegado');
        self::assertTrue($this->isStored((string) $futureGuardia->getLines()[0]->getPath()));
    }

    public function testUnEncargoSueltoCuentaPorElDiaEnQueSeHizo(): void
    {
        $yesterday = $this->order('2026-10-13 23:59');
        $yesterdayPath = (string) $yesterday->getLines()[0]->getPath();
        $today = $this->order('2026-10-14 00:01');

        $purged = $this->purger->purge(new \DateTimeImmutable(self::NOW));

        self::assertSame(['covers' => 0, 'orders' => 1, 'unremoved' => []], $purged);
        self::assertFalse($this->isStored($yesterdayPath));
        self::assertNotNull($today->getLines()[0]->getPath());
        self::assertTrue($this->isStored((string) $today->getLines()[0]->getPath()));
    }

    /**
     * Un encargo con varios archivos los borra todos, y el recuento es de documentos, no de encargos.
     */
    public function testUnEncargoConVariosArchivosLosBorraTodos(): void
    {
        $order = $this->order('2026-10-13 09:00', files: 3);
        $paths = array_map(static fn ($l): string => (string) $l->getPath(), $order->getLines());

        self::assertSame(['covers' => 0, 'orders' => 3, 'unremoved' => []], $this->purger->purge(new \DateTimeImmutable(self::NOW)));
        self::assertSame([false, false, false], array_map(fn (string $p): bool => $this->isStored($p), $paths));
        self::assertFalse($order->hasDocument());
        self::assertNotNull($order->getDocumentPurgedAt());
    }

    public function testUnaSegundaPasadaNoEncuentraNada(): void
    {
        $this->order('2026-06-10 10:00', $this->coverWithDocument('2026-06-10'));
        $this->purger->purge(new \DateTimeImmutable(self::NOW));

        self::assertSame(['covers' => 0, 'orders' => 0, 'unremoved' => []], $this->purger->purge(new \DateTimeImmutable(self::NOW)));
    }

    /**
     * The file can already be gone (a manual clean-up, a restored backup without the uploads): the record
     * is still marked, and the run does not fail.
     */
    public function testSiElFicheroYaNoEstabaMarcaElRegistroIgual(): void
    {
        $cover = $this->coverWithDocument('2026-06-19');
        $this->uploader->remove((string) $cover->getTaskDocumentPath());

        self::assertSame(['covers' => 1, 'orders' => 0, 'unremoved' => []], $this->purger->purge(new \DateTimeImmutable(self::NOW)));
        self::assertNotNull($cover->getTaskDocumentPurgedAt());
    }

    /**
     * An order whose e-mail never left: the copy room does not have the file, so it stays for «Reintentar
     * envío» a week past its day, and only then goes.
     */
    public function testUnEncargoSinEnviarConservaElDocumentoUnaSemana(): void
    {
        $retryable = $this->order('2026-10-13 10:00', sent: false);
        $abandoned = $this->order('2026-10-06 10:00', sent: false);
        $abandonedPath = (string) $abandoned->getLines()[0]->getPath();

        $purged = $this->purger->purge(new \DateTimeImmutable(self::NOW));

        self::assertSame(['covers' => 0, 'orders' => 1, 'unremoved' => []], $purged);
        self::assertNotNull($retryable->getLines()[0]->getPath(), 'aún se puede reintentar el envío');
        self::assertTrue($this->isStored((string) $retryable->getLines()[0]->getPath()));
        self::assertNull($abandoned->getLines()[0]->getPath(), 'pasada la semana se borra aunque nunca saliera');
        self::assertFalse($this->isStored($abandonedPath));
    }
}
