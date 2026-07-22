<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AcademicYear;
use App\Entity\Role;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;

/**
 * The self-service timetable import screen at /admin/horario: gated to administration like the rest of
 * /admin, it lets the equipo directivo upload the two Peñalara exports for a course, runs the shared
 * {@see \App\Guardia\TimetableImporter} and shows the reconciliation result.
 */
final class AdminTimetableImportTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

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
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function admin(): User
    {
        $adminRole = (new Role())->setCode('direction')->setName('Dirección')->setAdmin(true);
        $this->em->persist($adminRole);
        $user = (new User())->setFullName('Directora Test')->setEmail('director@centro.test')->addAssignedRole($adminRole);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function academicYear(string $schoolYear): AcademicYear
    {
        $start = (int) substr($schoolYear, 0, 4);

        return (new AcademicYear())
            ->setSchoolYear($schoolYear)
            ->setTerm1Start(new \DateTimeImmutable($start.'-09-15'))
            ->setTerm1End(new \DateTimeImmutable($start.'-12-22'))
            ->setTerm2Start(new \DateTimeImmutable(($start + 1).'-01-08'))
            ->setTerm2End(new \DateTimeImmutable(($start + 1).'-03-27'))
            ->setTerm3Start(new \DateTimeImmutable(($start + 1).'-04-07'))
            ->setTerm3End(new \DateTimeImmutable(($start + 1).'-06-22'));
    }

    /**
     * Writes the given XML to a temporary .xml file and returns its path, for a form file upload.
     *
     * @param string $contents the XML contents
     *
     * @return string the temporary file path
     */
    private function tmpXml(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tt').'.xml';
        file_put_contents($path, $contents);

        return $path;
    }

    public function testAdminSeesTheImportForm(): void
    {
        $this->client->loginUser($this->admin());
        $this->client->request('GET', '/admin/horario');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Importar horario');
    }

    public function testNonAdminIsForbidden(): void
    {
        $teacher = (new User())->setFullName('Docente Test')->setEmail('profe@centro.test');
        $this->em->persist($teacher);
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/admin/horario');

        self::assertResponseStatusCodeSame(403);
    }

    public function testUploadImportsScheduleAndReportsTheUnmatched(): void
    {
        $year = $this->academicYear('2026-2027');
        $this->em->persist($year);
        // Jane matches by name (recording her code); Ghost matches nobody and stays unmatched.
        $this->em->persist((new User())->setFullName('Jane Doe Smith')->setEmail('jane@centro.test'));
        $this->em->flush();

        $this->client->loginUser($this->admin());
        $crawler = $this->client->request('GET', '/admin/horario');

        $form = $crawler->selectButton('Importar')->form();
        $form['timetable_import[academicYear]'] = (string) $year->getId();
        $planificador = $form['timetable_import[planificador]'];
        $horario = $form['timetable_import[horario]'];
        self::assertInstanceOf(FileFormField::class, $planificador);
        self::assertInstanceOf(FileFormField::class, $horario);
        $planificador->upload($this->tmpXml(self::PLANIFICADOR));
        $horario->upload($this->tmpXml(self::HORARIO));
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card', 'profesores emparejados');
        self::assertSelectorTextContains('.card', 'Ghost Person');

        $entries = $this->em->getRepository(ScheduleEntry::class)->findAll();
        self::assertCount(1, $entries, 'only Jane\'s single cell was imported');
        self::assertSame('2026-2027', $entries[0]->getAcademicYear()->getSchoolYear());
    }
}
