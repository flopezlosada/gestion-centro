<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\ImportRosterCommand;
use App\Entity\Role;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The roster import upserts people, departments and roles from a CSV, mapping each "cargo" to a role
 * and matching by e-mail/code so a re-run (a mid-year change) never duplicates.
 */
final class ImportRosterCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $csv;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->csv = tempnam(sys_get_temp_dir(), 'roster').'.csv';
        file_put_contents($this->csv, <<<CSV
            full_name,email,department,cargo
            Ana Directora Ruiz,ana.dir@educa.madrid.org,Matemáticas,DIRECTORA
            Pedro Docente Sanz,pedro.doc@educa.madrid.org,Matemáticas,TUTORA E1A
            Luis Tutor Gil,luis.tut@educa.madrid.org,Tecnología,TUTOR E2B
            CSV);
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);
        parent::tearDown();
    }

    private function runImport(): CommandTester
    {
        $command = self::getContainer()->get(ImportRosterCommand::class);
        $tester = new CommandTester($command);
        $tester->execute(['csv' => $this->csv]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    public function testImportsPeopleDepartmentsAndRoles(): void
    {
        $this->runImport();

        // Three people, two departments.
        self::assertCount(3, $this->em->getRepository(User::class)->findAll());
        self::assertNotNull($this->em->getRepository(Unit::class)->findOneBy(['code' => 'dept_matematicas']));
        self::assertNotNull($this->em->getRepository(Unit::class)->findOneBy(['code' => 'dept_tecnologia']));

        $director = $this->em->getRepository(User::class)->findOneBy(['email' => 'ana.dir@educa.madrid.org']);
        self::assertInstanceOf(User::class, $director);
        self::assertSame('Matemáticas', $director->getUnit()?->getName());

        $codes = array_map(static fn (Role $r) => $r->getCode(), $director->getAssignedRoles()->toArray());
        self::assertContains('docente', $codes, 'everyone is a teacher');
        self::assertContains('direccion', $codes);

        // Only Dirección gets back-office access.
        $direccion = $this->em->getRepository(Role::class)->findOneBy(['code' => 'direccion']);
        self::assertInstanceOf(Role::class, $direccion);
        self::assertSame(PermissionLevel::WRITE, $direccion->getLevel(Area::ADMINISTRATION));
    }

    public function testReRunIsIdempotent(): void
    {
        $this->runImport();
        $this->runImport();

        self::assertCount(3, $this->em->getRepository(User::class)->findAll(), 'no duplicates on re-run');
        self::assertCount(1, $this->em->getRepository(Unit::class)->findBy(['code' => 'dept_matematicas']));
    }
}
