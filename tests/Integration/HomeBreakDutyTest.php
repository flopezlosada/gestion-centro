<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Agenda\AgendaEntry;
use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakDutyGap;
use App\Entity\BreakZone;
use App\Entity\NonLectiveDay;
use App\Entity\TimeSlot;
use App\Entity\User;
use App\Enum\BreakPeriod;
use App\Enum\TimeSlotKind;
use App\Enum\Weekday;
use App\Home\HomeDashboard;
use App\Tests\Support\OwnsTheBreakZoneCatalogue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La guardia de RECREO en Inicio.
 *
 * El cuadrante de recreo es un patrón semanal fijo de todo el curso, así que "Mis guardias" lo enuncia
 * una sola vez ("los martes, patio"). Inicio contesta otra pregunta —qué me toca HOY— y hasta ahora no
 * lo decía: un martes por la mañana la pantalla no mencionaba el patio, y un recreo que nadie recuerda
 * es una zona que se queda sin vigilar. Aquí se proyecta el patrón sobre el día, sin guardar nada por
 * día, y se comprueba lo que no debe hacer: no enseñar un borrador, no inventar recreos en festivo y no
 * llamar a quien ya ha apuntado que falta.
 *
 * La fecha es FIJA (un lunes del curso 2025-2026) y no "hoy": el bloque depende del día de la semana y
 * del calendario escolar, así que un test anclado al reloj del runner contaría una cosa distinta cada
 * día que corriera.
 */
final class HomeBreakDutyTest extends KernelTestCase
{
    use OwnsTheBreakZoneCatalogue;

    private EntityManagerInterface $em;
    private HomeDashboard $dashboard;
    private AcademicYear $year;
    private User $teacher;
    private BreakZone $patio;
    private BreakDutyAssignment $duty;

    /** Un lunes lectivo del curso 2025-2026. */
    private const MONDAY = '2025-11-03';

    /** El martes siguiente: el mismo profesor NO tiene plaza ese día. */
    private const TUESDAY = '2025-11-04';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->dashboard = self::getContainer()->get(HomeDashboard::class);
        $this->emptyTheBreakZoneCatalogue($this->em);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-23'))
            // Anunciado: mientras es borrador el equipo directivo sigue moviendo plazas.
            ->setBreakRotaAnnouncedAt(new \DateTimeImmutable('2025-09-30 10:00'));
        $this->em->persist($this->year);

        // El marco horario del centro, recortado a lo que este bloque lee: los dos recreos. Las horas
        // salen de aquí por POSICIÓN (primer recreo, segundo), nunca por índice de tramo.
        $this->slot(3, '11:10', '11:35');
        $this->slot(6, '13:20', '13:30');

        $this->patio = (new BreakZone())->setName('Patio')->setWeight(3);
        $this->em->persist($this->patio);

        $this->teacher = (new User())->setFullName('Ana Recreo Ruiz')->setEmail('ana.recreo@educa.madrid.org');
        $this->em->persist($this->teacher);

        $this->duty = (new BreakDutyAssignment())
            ->setAcademicYear($this->year)
            ->setTeacher($this->teacher)
            ->setWeekday(Weekday::MONDAY)
            ->setZone($this->patio)
            ->setPeriod(BreakPeriod::FIRST);
        $this->em->persist($this->duty);

        $this->em->flush();
    }

    public function testTheRecreoOfTheDayTakesItsPlaceInTheDayTimeline(): void
    {
        $home = $this->homeOn(self::MONDAY, '08:30');

        self::assertCount(1, $home['breakDutiesToday']);
        self::assertSame('11:10', $home['breakDutiesToday'][0]['startsAt']?->format('H:i'), 'la hora sale del marco horario, no del índice de tramo');

        $rows = $this->breakRows($home);
        self::assertCount(1, $rows, 'el recreo ocupa su hora en "Tu día", como una guardia más');
        self::assertSame($this->duty->getId(), $rows[0]['entry']->breakDuty?->getId());
        self::assertSame('2025-11-03 11:10', $rows[0]['entry']->date->format('Y-m-d H:i'), 'se ordena por el reloj del día, junto al resto');
        self::assertSame(160, $rows[0]['minutesUntil'], 'y dice cuánto queda, como el resto de la línea temporal');
        self::assertSame('now', $rows[0]['state'], 'a las 08:30 el recreo es lo siguiente que hay que atender');
    }

    public function testARecreoAlreadyOverIsDimmedInsteadOfDropped(): void
    {
        // Media tarde: el recreo de las 11:10 terminó hace horas. Sigue en la lista —el día se lee
        // entero— pero atenuado, que es la diferencia entre "ya lo hiciste" y "no te tocaba".
        $rows = $this->breakRows($this->homeOn(self::MONDAY, '17:00'));

        self::assertCount(1, $rows);
        self::assertSame('past', $rows[0]['state']);
        self::assertNull($rows[0]['minutesUntil'], 'lo que ya pasó no tiene cuenta atrás');
    }

    public function testBothRecreosOfADayAreListedSeparately(): void
    {
        // El centro permite vigilar el recreo grande en una zona y el corto en otra: son dos plazas.
        $biblioteca = (new BreakZone())->setName('Biblioteca')->setWeight(1);
        $this->em->persist($biblioteca);
        $this->em->persist((new BreakDutyAssignment())
            ->setAcademicYear($this->year)
            ->setTeacher($this->teacher)
            ->setWeekday(Weekday::MONDAY)
            ->setZone($biblioteca)
            ->setPeriod(BreakPeriod::SECOND));
        $this->em->flush();

        $rows = $this->breakRows($this->homeOn(self::MONDAY, '08:30'));

        self::assertCount(2, $rows);
        self::assertSame(['11:10', '13:20'], array_map(static fn (array $r): string => $r['startsAt']?->format('H:i') ?? '', $rows));
    }

    public function testAWeekdayWithNoPlaceOnTheRotaShowsNothing(): void
    {
        // Su plaza es de los lunes; el martes Inicio no puede insinuar que le toca.
        $home = $this->homeOn(self::TUESDAY, '08:30');

        self::assertSame([], $home['breakDutiesToday']);
        self::assertSame([], $this->breakRows($home));
    }

    public function testADraftRotaIsNotShownYet(): void
    {
        // Mismo gate que "Mis guardias": un cuadrante sin anunciar todavía se está retocando, y enseñarlo
        // haría que la gente apunte un reparto que va a cambiar.
        $this->year->setBreakRotaAnnouncedAt(null);
        $this->em->flush();

        self::assertSame([], $this->homeOn(self::MONDAY, '08:30')['breakDutiesToday']);
    }

    public function testALectivelessDayHasNoRecreo(): void
    {
        // Un lunes de fiesta sigue siendo lunes en el cuadrante, pero no hay patio que vigilar.
        $this->em->persist((new NonLectiveDay())->setDate(new \DateTimeImmutable(self::MONDAY))->setDescription('Festivo local'));
        $this->em->flush();

        self::assertSame([], $this->homeOn(self::MONDAY, '08:30')['breakDutiesToday']);
    }

    public function testADayAlreadyRegisteredAsAGapIsNotReclaimed(): void
    {
        // Quien ha apuntado que falta ya ha soltado su recreo: queda como hueco y el aviso va al equipo
        // directivo. Decirle "hoy te toca el patio" contradiría su propio parte de ausencia.
        $this->em->persist((new BreakDutyGap())->setAssignment($this->duty)->setDate(new \DateTimeImmutable(self::MONDAY)));
        $this->em->flush();

        $home = $this->homeOn(self::MONDAY, '08:30');

        self::assertSame([], $home['breakDutiesToday']);
        self::assertSame([], $this->breakRows($home));
    }

    public function testWithoutAnImportedTimetableTheRecreoStillShowsWithoutAnHour(): void
    {
        // Sin marco horario no se sabe a qué hora es el recreo. Perderlo por eso sería perder una guardia
        // real: entra igual, sin reloj, y no se da por pasado a ninguna hora del día.
        foreach ($this->em->getRepository(TimeSlot::class)->findAll() as $slot) {
            $this->em->remove($slot);
        }
        $this->em->flush();

        $rows = $this->breakRows($this->homeOn(self::MONDAY, '17:00'));

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['startsAt']);
        self::assertNotSame('past', $rows[0]['state'], 'no saber la hora no es razón para darlo por hecho');
    }

    /**
     * The home view-model for the teacher on a given day and clock time.
     *
     * @param string $day  the day, "YYYY-MM-DD"
     * @param string $time the clock time on it, "HH:MM"
     *
     * @return array<string, mixed> the view-model
     */
    private function homeOn(string $day, string $time): array
    {
        return $this->dashboard->baseFor(
            $this->teacher,
            new \DateTimeImmutable($day),
            new \DateTimeImmutable($day.' '.$time),
        );
    }

    /**
     * The recreo rows of "Tu día", in the order the screen paints them.
     *
     * @param array<string, mixed> $home the view-model
     *
     * @return list<array{entry: AgendaEntry, startsAt: ?\DateTimeImmutable, minutesUntil: ?int, state: string}> the rows
     */
    private function breakRows(array $home): array
    {
        /** @var list<array{entry: AgendaEntry, startsAt: ?\DateTimeImmutable, minutesUntil: ?int, state: string}> $timeline */
        $timeline = $home['dayTimeline'];

        return array_values(array_filter(
            $timeline,
            static fn (array $row): bool => AgendaEntry::KIND_BREAK_DUTY === $row['entry']->kind,
        ));
    }

    /**
     * Persists one recreo of the course's marco horario.
     *
     * @param int    $index the period index within the day
     * @param string $from  start time, "HH:MM"
     * @param string $to    end time, "HH:MM"
     */
    private function slot(int $index, string $from, string $to): void
    {
        $this->em->persist((new TimeSlot())
            ->setAcademicYear($this->year)
            ->setSlotIndex($index)
            ->setStartsAt(new \DateTimeImmutable($from))
            ->setEndsAt(new \DateTimeImmutable($to))
            ->setKind(TimeSlotKind::BREAK_TIME));
    }
}
