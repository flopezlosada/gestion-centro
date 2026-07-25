<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\ImportTimetableCommand;
use App\Entity\AcademicYear;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\ScheduleEntrySource;
use App\Enum\Weekday;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The timetable import reconciles Peñalara teachers to users (by code, then by an order-insensitive
 * name match that records the code), persists their schedule, reports the unmatched, and is
 * idempotent on re-run.
 *
 * It also has to share the table with a second writer — the manual "horario de guardias" editor — so
 * the cases below pin down who wins where: a hand-marked guardia survives a re-import, is never
 * duplicated by an export cell on the same period, and only gives way to a lesson.
 */
final class ImportTimetableCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $planificador;
    private string $horario;

    private const PLANIFICADOR = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <datosGHC>
            <marcosDeHorario>
                <marcoHorario id="A">
                    <tramo><indice>0</indice><horaEntrada>08:25:00</horaEntrada><horaSalida>09:20:00</horaSalida><Tipo>lectivo</Tipo><clavX>1000</clavX></tramo>
                    <tramo><indice>1</indice><horaEntrada>09:20:00</horaEntrada><horaSalida>10:15:00</horaSalida><Tipo>lectivo</Tipo><clavX>1001</clavX></tramo>
                </marcoHorario>
            </marcosDeHorario>
            <profesor><nombreCompleto>Doe Smith, Jane</nombreCompleto><claveDeExportacion>777</claveDeExportacion></profesor>
            <profesor><nombreCompleto>Ghost Person, No</nombreCompleto><claveDeExportacion>888</claveDeExportacion></profesor>
            <grupo submarco="A"><abreviatura>1ºA</abreviatura><claveDeExportacion>500-900</claveDeExportacion></grupo>
            <aula><abreviatura>A10</abreviatura><claveDeExportacion>60</claveDeExportacion></aula>
            <materia><abreviatura>Mates</abreviatura><claveDeExportacion>2000</claveDeExportacion></materia>
            <tarea><nombreCompleto>SEC - Guardias</nombreCompleto><claveDeExportacion>65</claveDeExportacion></tarea>
        </datosGHC>
        XML;

    private const HORARIO = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <SERVICIO modulo="HORARIOS">
            <BLOQUE_DATOS>
                <grupo_datos seq="HORARIOS_REGULARES">
                    <grupo_datos seq="HORARIO_REGULAR_PROFESOR_1">
                        <dato nombre_dato="X_EMPLEADO">777</dato>
                        <grupo_datos seq="ACTIVIDAD_1">
                            <dato nombre_dato="N_DIASEMANA">1</dato><dato nombre_dato="X_TRAMO">1000</dato>
                            <dato nombre_dato="X_DEPENDENCIA">60</dato><dato nombre_dato="X_UNIDAD">900</dato>
                            <dato nombre_dato="X_OFERTAMATRIG">500</dato><dato nombre_dato="X_MATERIAOMG">2000</dato>
                            <dato nombre_dato="X_ACTIVIDAD">1</dato>
                        </grupo_datos>
                        <grupo_datos seq="ACTIVIDAD_2">
                            <dato nombre_dato="N_DIASEMANA">3</dato><dato nombre_dato="X_TRAMO">1001</dato>
                            <dato nombre_dato="X_UNIDAD"></dato><dato nombre_dato="X_ACTIVIDAD">65</dato>
                        </grupo_datos>
                    </grupo_datos>
                    <grupo_datos seq="HORARIO_REGULAR_PROFESOR_2">
                        <dato nombre_dato="X_EMPLEADO">888</dato>
                        <grupo_datos seq="ACTIVIDAD_1">
                            <dato nombre_dato="N_DIASEMANA">2</dato><dato nombre_dato="X_TRAMO">1000</dato>
                            <dato nombre_dato="X_DEPENDENCIA">60</dato><dato nombre_dato="X_UNIDAD">900</dato>
                            <dato nombre_dato="X_OFERTAMATRIG">500</dato><dato nombre_dato="X_MATERIAOMG">2000</dato>
                            <dato nombre_dato="X_ACTIVIDAD">1</dato>
                        </grupo_datos>
                    </grupo_datos>
                </grupo_datos>
            </BLOQUE_DATOS>
        </SERVICIO>
        XML;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        // The target course must exist before its timetable can be imported.
        $year = (new AcademicYear())
            ->setSchoolYear('2026-2027')
            ->setTerm1Start(new \DateTimeImmutable('2026-09-01'))
            ->setTerm1End(new \DateTimeImmutable('2026-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2027-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2027-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2027-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2027-06-30'));
        $this->em->persist($year);

        // Jane exists with her name in a different order than Peñalara's "Apellidos, Nombre", and no
        // Peñalara code yet: the import must still match her by name and record the code.
        $jane = (new User())->setFullName('Jane Doe Smith')->setEmail('jane@educa.madrid.org');
        $this->em->persist($jane);
        $this->em->flush();

        $this->planificador = tempnam(sys_get_temp_dir(), 'plan').'.xml';
        $this->horario = tempnam(sys_get_temp_dir(), 'hor').'.xml';
        file_put_contents($this->planificador, self::PLANIFICADOR);
        file_put_contents($this->horario, self::HORARIO);
    }

    protected function tearDown(): void
    {
        @unlink($this->planificador);
        @unlink($this->horario);
        parent::tearDown();
    }

    private function runImport(): CommandTester
    {
        $tester = new CommandTester(self::getContainer()->get(ImportTimetableCommand::class));
        $tester->execute(['curso' => '2026-2027', 'planificador' => $this->planificador, 'horario' => $this->horario]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    public function testMatchesByNameRecordsCodeAndImportsSchedule(): void
    {
        $this->runImport();
        $this->em->clear();

        $jane = $this->em->getRepository(User::class)->findOneBy(['email' => 'jane@educa.madrid.org']);
        self::assertInstanceOf(User::class, $jane);
        self::assertSame('777', $jane->getPenalaraCode(), 'the unique name match records the Peñalara code');

        $entries = $this->em->getRepository(ScheduleEntry::class)->findBy(['teacher' => $jane]);
        self::assertCount(2, $entries, 'one lective + one guardia');

        $guardias = array_filter($entries, static fn (ScheduleEntry $e): bool => ScheduleActivityKind::GUARDIA === $e->getKind());
        self::assertCount(1, $guardias);
    }

    public function testUnmatchedTeacherIsReportedAndNotImported(): void
    {
        $tester = $this->runImport();

        self::assertStringContainsString('sin emparejar', $tester->getDisplay());
        self::assertStringContainsString('Ghost Person', $tester->getDisplay());

        // Nobody matches code 888, so no schedule was created for it.
        self::assertCount(2, $this->em->getRepository(ScheduleEntry::class)->findAll(), 'only Jane\'s two entries');
    }

    public function testReRunIsIdempotent(): void
    {
        $this->runImport();
        $this->runImport();

        self::assertCount(2, $this->em->getRepository(ScheduleEntry::class)->findAll(), 'a re-run replaces, never duplicates');
    }

    public function testImportingIntoAnUnknownCourseFailsAndWritesNothing(): void
    {
        $tester = new CommandTester(self::getContainer()->get(ImportTimetableCommand::class));
        $exit = $tester->execute(['curso' => '2099-2100', 'planificador' => $this->planificador, 'horario' => $this->horario]);

        self::assertSame(1, $exit, 'a missing course structure fails the command');
        self::assertStringContainsString('No existe la estructura del curso', $tester->getDisplay());
        self::assertCount(0, $this->em->getRepository(ScheduleEntry::class)->findAll());
    }

    public function testEntriesAreTiedToTheTargetCourse(): void
    {
        $this->runImport();
        $this->em->clear();

        $entries = $this->em->getRepository(ScheduleEntry::class)->findAll();
        self::assertNotEmpty($entries);
        foreach ($entries as $entry) {
            self::assertSame('2026-2027', $entry->getAcademicYear()->getSchoolYear(), 'every imported cell belongs to the course it was imported for');
        }
    }

    public function testImportingAnotherCourseLeavesTheFirstUntouched(): void
    {
        // Import 2026-2027, then the same teachers into a second course: the first course's entries
        // must survive, since a re-import only replaces its own course's rows.
        $this->runImport();

        $next = (new AcademicYear())
            ->setSchoolYear('2027-2028')
            ->setTerm1Start(new \DateTimeImmutable('2027-09-01'))
            ->setTerm1End(new \DateTimeImmutable('2027-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2028-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2028-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2028-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2028-06-30'));
        $this->em->persist($next);
        $this->em->flush();

        $tester = new CommandTester(self::getContainer()->get(ImportTimetableCommand::class));
        $tester->execute(['curso' => '2027-2028', 'planificador' => $this->planificador, 'horario' => $this->horario]);
        $tester->assertCommandIsSuccessful();
        $this->em->clear();

        $repo = $this->em->getRepository(ScheduleEntry::class);
        $bySchoolYear = [];
        foreach ($repo->findAll() as $entry) {
            $bySchoolYear[$entry->getAcademicYear()->getSchoolYear()][] = $entry;
        }
        self::assertCount(2, $bySchoolYear['2026-2027'] ?? [], 'the first course keeps its entries');
        self::assertCount(2, $bySchoolYear['2027-2028'] ?? [], 'the second course gets its own entries');
    }

    public function testHandMarkedGuardiaOnAFreePeriodSurvivesTheImport(): void
    {
        // Friday 1st period: Jane is free there and someone marked her on guardia by hand. The export
        // says nothing about it, so the import must leave it alone — this is the whole point of the flag.
        $this->runImport();
        $jane = $this->teacher('jane@educa.madrid.org');
        $this->handMarkedGuardia($jane, Weekday::FRIDAY, 0);

        $this->runImport();
        $this->em->clear();

        $manual = $this->em->getRepository(ScheduleEntry::class)->findBy(['source' => ScheduleEntrySource::MANUAL]);
        self::assertCount(1, $manual, 'the hand-marked guardia survives a re-import');
        self::assertSame(Weekday::FRIDAY, $manual[0]->getWeekday());
        self::assertCount(3, $this->em->getRepository(ScheduleEntry::class)->findAll(), 'two imported cells plus the hand-marked one');
    }

    public function testExportDoesNotDuplicateAGuardiaAlreadyMarkedByHand(): void
    {
        // Wednesday 2nd period is exactly where the export puts Jane's guardia. Marking it by hand
        // first must not leave two rows for one period — that would count her twice in the pool.
        $jane = $this->teacher('jane@educa.madrid.org');
        $this->handMarkedGuardia($jane, Weekday::WEDNESDAY, 1);

        $tester = $this->runImport();
        $this->em->clear();

        $entries = $this->em->getRepository(ScheduleEntry::class)->findAll();
        self::assertCount(2, $entries, 'one lective from the export plus the single hand-marked guardia');
        $guardias = array_filter($entries, static fn (ScheduleEntry $e): bool => ScheduleActivityKind::GUARDIA === $e->getKind());
        self::assertCount(1, $guardias);
        self::assertSame(ScheduleEntrySource::MANUAL, reset($guardias)->getSource(), 'the hand-marked cell stands, the export one is skipped');
        self::assertStringContainsString('se han respetado', $tester->getDisplay());
    }

    public function testANewLessonRemovesTheHandMarkedGuardiaUnderneath(): void
    {
        // Monday 1st period is a lesson in the export. A guardia marked there by hand cannot stand:
        // nobody is on guardia while teaching, so the timetable wins and the manual cell goes.
        $jane = $this->teacher('jane@educa.madrid.org');
        $this->handMarkedGuardia($jane, Weekday::MONDAY, 0);

        $tester = $this->runImport();
        $this->em->clear();

        $monday = $this->em->getRepository(ScheduleEntry::class)->findBy(['weekday' => Weekday::MONDAY, 'slotIndex' => 0]);
        self::assertCount(1, $monday, 'the lesson replaces the hand-marked guardia, it does not join it');
        self::assertSame(ScheduleActivityKind::LECTIVE, $monday[0]->getKind());
        self::assertStringContainsString('se han quitado', $tester->getDisplay());
    }

    public function testATeacherTheExportNoLongerCarriesIsReportedNotDeleted(): void
    {
        // Someone who left the centre keeps a timetable nobody re-imports. Deleting it on a guess would
        // be worse than saying so: their guardia slots keep feeding the engine until a person decides.
        $this->runImport();
        $gone = (new User())->setFullName('Ex Profesor Marchado')->setEmail('gone@educa.madrid.org');
        $this->em->persist($gone);
        $this->handMarkedGuardia($gone, Weekday::TUESDAY, 0);

        $tester = $this->runImport();
        $this->em->clear();

        self::assertStringContainsString('Ex Profesor Marchado', $tester->getDisplay());
        self::assertCount(1, $this->em->getRepository(ScheduleEntry::class)->findBy(['teacher' => $gone]), 'their cells are left alone');
    }

    /**
     * Loads a teacher by e-mail, failing the test when they are missing.
     *
     * @param string $email the teacher's e-mail
     *
     * @return User the teacher
     */
    private function teacher(string $email): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * Marks a teacher on guardia by hand at a weekday and period of the test course, as the manual
     * "horario de guardias" editor would.
     *
     * @param User    $teacher   the teacher on duty
     * @param Weekday $weekday   the weekday
     * @param int     $slotIndex the period index
     */
    private function handMarkedGuardia(User $teacher, Weekday $weekday, int $slotIndex): void
    {
        $year = $this->em->getRepository(AcademicYear::class)->findOneBy(['schoolYear' => '2026-2027']);
        self::assertInstanceOf(AcademicYear::class, $year);

        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($year)
            ->setTeacher($teacher)
            ->setWeekday($weekday)
            ->setSlotIndex($slotIndex)
            ->setStartsAt(new \DateTimeImmutable('08:25:00'))
            ->setEndsAt(new \DateTimeImmutable('09:20:00'))
            ->setKind(ScheduleActivityKind::GUARDIA)
            ->setSource(ScheduleEntrySource::MANUAL));
        $this->em->flush();
    }
}
