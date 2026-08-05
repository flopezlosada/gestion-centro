<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\ScheduleEntry;
use App\Entity\Substitution;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\ScheduleEntrySource;
use App\Enum\Weekday;
use App\Guardia\SubstitutionApplier;
use App\Guardia\TimetableImporter;
use App\Repository\ScheduleEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Reimportar el horario de Peñalara con una baja larga en curso.
 *
 * Esta es la trampa que costaría el día si no estuviera cubierta, y no es "se pierde la sustitución":
 * es peor. {@see ScheduleEntryRepository::replaceForTeachers()} borra por "profesor IN (…) AND source =
 * penalara"; con las filas ya a nombre de quien sustituye ese borrado no encuentra nada, y el import
 * inserta un juego nuevo para la persona de baja. El resultado es LAS DOS con el mismo horario, las dos
 * en el pool de guardias, sin un solo error por ningún lado.
 *
 * El export sigue nombrando a la persona de baja —su código de Peñalara no cambia porque esté ausente—,
 * así que la única forma honesta de reimportar es devolverle el horario un momento y volver a
 * traspasarlo al terminar.
 */
final class SubstitutionReimportTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TimetableImporter $importer;
    private ScheduleEntryRepository $schedule;
    private AcademicYear $year;
    private User $jane;
    private User $substitute;

    /**
     * El mismo par de exports que {@see ImportTimetableCommandTest}: Jane (código 777) con una clase el
     * lunes y una guardia el miércoles.
     */
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
                </grupo_datos>
            </BLOQUE_DATOS>
        </SERVICIO>
        XML;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->importer = self::getContainer()->get(TimetableImporter::class);
        $this->schedule = self::getContainer()->get(ScheduleEntryRepository::class);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-23'));
        $this->em->persist($this->year);

        $this->jane = (new User())->setFullName('Jane Doe Smith')->setEmail('jane@educa.madrid.org');
        $this->substitute = (new User())->setFullName('Sara Sustituta')->setEmail('sara@educa.madrid.org');
        $this->em->persist($this->jane);
        $this->em->persist($this->substitute);
        $this->em->flush();

        // Primer import: Jane recibe su horario, y a partir de ahí se pone de baja.
        $this->import();
    }

    public function testReimportingDoesNotDuplicateTheTimetableOfSomebodyOnLeave(): void
    {
        $this->substitutionInPlace();

        $this->import();

        self::assertCount(2, $this->em->getRepository(ScheduleEntry::class)->findAll(), 'el import reemplaza, nunca duplica');
        self::assertCount(2, $this->schedule->findByTeacherAndYear($this->year, $this->substitute), 'y quien sustituye conserva el horario');
        self::assertCount(0, $this->schedule->findByTeacherAndYear($this->year, $this->jane));
    }

    public function testTheReimportedTimetableIsTheNewOne(): void
    {
        // No basta con que las celdas sigan siendo dos: tienen que ser las del fichero recién cargado, no
        // las de antes de la baja. Se comprueba por id, que es lo único que distingue unas de otras.
        $this->substitutionInPlace();
        $before = array_map(static fn (ScheduleEntry $e): ?int => $e->getId(), $this->schedule->findByTeacherAndYear($this->year, $this->substitute));

        $this->import();
        $after = array_map(static fn (ScheduleEntry $e): ?int => $e->getId(), $this->schedule->findByTeacherAndYear($this->year, $this->substitute));

        self::assertSame([], array_intersect($before, $after), 'las celdas viejas se fueron con el import y las nuevas llegaron traspasadas');
    }

    public function testWhoeverStandsInIsNotReportedAsHavingAStrandedTimetable(): void
    {
        // Su horario no es uno que nadie reimporta: es el prestado, y el propio import lo repone. Un
        // aviso que sale en cada import mientras dura la baja deja de leerse.
        $this->substitutionInPlace();

        $result = $this->importer->import($this->year, self::PLANIFICADOR, self::HORARIO, dryRun: true);

        self::assertSame([], $result->stale);
        self::assertCount(1, $result->substitutions, 'y en su lugar el preview anuncia la sustitución');
    }

    public function testThePreviewCountsTheCellsItIsAboutToReplace(): void
    {
        // Con las filas a nombre de otra persona, contar solo las de quien el export nombra daría cero:
        // "0 celdas sustituyen a las 0 que ya había" justo para el horario que más se está moviendo.
        $this->substitutionInPlace();

        $result = $this->importer->import($this->year, self::PLANIFICADOR, self::HORARIO, dryRun: true);

        self::assertSame(2, $result->replacedCount);
    }

    public function testAHandMarkedGuardiaAddedDuringTheLeaveIsStillHonoured(): void
    {
        // Mientras dura la baja, una guardia marcada a mano queda a nombre de quien sustituye, así que el
        // import —que busca por la persona que el export nombra— no la encontraría. El síntoma no sería
        // un error: sería esa persona con una guardia y una clase a la misma hora al reponer el horario.
        $this->substitutionInPlace();
        $manual = (new ScheduleEntry())
            ->setAcademicYear($this->year)
            ->setTeacher($this->substitute)
            ->setWeekday(Weekday::TUESDAY)
            ->setSlotIndex(0)
            ->setStartsAt(new \DateTimeImmutable('08:25'))
            ->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(ScheduleActivityKind::GUARDIA)
            ->setSource(ScheduleEntrySource::MANUAL);
        $this->em->persist($manual);
        $this->em->flush();
        $manualId = (int) $manual->getId();

        $this->import();
        $this->em->clear();

        $survivor = $this->em->getRepository(ScheduleEntry::class)->find($manualId);
        self::assertInstanceOf(ScheduleEntry::class, $survivor, 'la guardia marcada a mano sobrevive al import');
        self::assertSame(
            $this->substitute->getId(),
            $survivor->getTeacher()->getId(),
            'y vuelve a manos de quien sustituye, como el resto del horario',
        );
    }

    /** Abre la sustitución de Jane y traspasa su horario. */
    private function substitutionInPlace(): Substitution
    {
        $substitution = (new Substitution())
            ->setAcademicYear($this->year)
            ->setSubstitutedTeacher($this->jane)
            ->setSubstitute($this->substitute)
            ->setStartedOn(new \DateTimeImmutable('2025-11-10'));
        self::getContainer()->get(SubstitutionApplier::class)->open($substitution, new \DateTimeImmutable('2025-11-10'));

        return $substitution;
    }

    /** Carga el par de exports en el curso de la prueba. */
    private function import(): void
    {
        $this->importer->import($this->year, self::PLANIFICADOR, self::HORARIO);
    }
}
