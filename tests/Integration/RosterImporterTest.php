<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Department;
use App\Entity\Role;
use App\Entity\User;
use App\Service\RosterImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Loading the claustro: what it creates, what it refuses to do twice, and — the property the
 * self-service screen depends on — that a preview writes absolutely nothing.
 */
final class RosterImporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RosterImporter $importer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->importer = self::getContainer()->get(RosterImporter::class);
    }

    public function testImportsPeopleWithTheirDepartmentAndCargo(): void
    {
        $result = $this->importer->import($this->csv([
            'Ana Gómez Ruiz,ana.gomez@educa.madrid.org,Matemáticas,DIRECTORA',
            'Luis Prat Sanz,luis.prat@educa.madrid.org,Lengua,TUTOR E1A',
            'Sara Vidal Mora,sara.vidal@educa.madrid.org,Lengua,',
        ]));

        self::assertSame(3, $result->rowCount);
        self::assertCount(3, $result->created);
        self::assertSame(0, $result->updated);
        self::assertFalse($result->dryRun);

        $ana = $this->em->getRepository(User::class)->findOneBy(['email' => 'ana.gomez@educa.madrid.org']);
        self::assertNotNull($ana);
        self::assertSame('Matemáticas', $ana->getUnit()?->getName());
        $codes = array_map(static fn (Role $r): string => $r->getCode(), $ana->getAssignedRoles()->toArray());
        self::assertContains('direction', $codes, 'the cargo becomes a role');
        self::assertContains('teacher', $codes, 'everybody is a teacher too');
    }

    public function testAPreviewWritesNothingAtAll(): void
    {
        $csv = $this->csv(['Ana Gómez Ruiz,ana.gomez@educa.madrid.org,Matemáticas,DIRECTORA']);

        $result = $this->importer->import($csv, true);
        // A flush that happens for any other reason must not carry half an import with it.
        $this->em->flush();
        $this->em->clear();

        self::assertTrue($result->dryRun);
        self::assertCount(1, $result->created);
        self::assertSame(['Matemáticas'], $result->departments, 'the preview still names the departments');
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'ana.gomez@educa.madrid.org']));
        self::assertNull($this->em->getRepository(Department::class)->findOneBy(['name' => 'Matemáticas']), 'not even a department is created');
    }

    public function testReimportingUpdatesInsteadOfDuplicating(): void
    {
        $this->importer->import($this->csv(['Ana Gómez Ruiz,ana.gomez@educa.madrid.org,Matemáticas,']));

        // Mid-year change: same person, new department.
        $result = $this->importer->import($this->csv(['Ana Gómez Ruiz,ana.gomez@educa.madrid.org,Física y Química,']));

        self::assertCount(0, $result->created);
        self::assertSame(1, $result->updated);
        self::assertCount(1, $this->em->getRepository(User::class)->findBy(['email' => 'ana.gomez@educa.madrid.org']));
        $this->em->clear();
        $ana = $this->em->getRepository(User::class)->findOneBy(['email' => 'ana.gomez@educa.madrid.org']);
        self::assertSame('Física y Química', $ana?->getUnit()?->getName());
    }

    public function testReportsTheLinesItCannotReadInsteadOfDroppingThem(): void
    {
        $result = $this->importer->import($this->csv([
            'Ana Gómez Ruiz,ana.gomez@educa.madrid.org,Matemáticas,',
            'Sin Correo Nadie,,Lengua,',
            'Columnas de menos',
        ]));

        self::assertSame(1, $result->rowCount);
        self::assertCount(2, $result->skipped, 'a person silently dropped is a person nobody can assign anything to');
        self::assertTrue($result->needsAttention());
    }

    public function testRefusesAFileThatIsNotARoster(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->importer->import("nombre;correo\nAna;ana@x.org");
    }

    /**
     * A roster CSV with the expected header.
     *
     * @param list<string> $lines the data lines
     *
     * @return string the CSV
     */
    private function csv(array $lines): string
    {
        return implode("\n", [implode(',', RosterImporter::HEADER), ...$lines]);
    }
}
