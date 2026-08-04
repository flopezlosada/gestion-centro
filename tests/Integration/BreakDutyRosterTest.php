<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakZone;
use App\Entity\TimeSlot;
use App\Entity\User;
use App\Enum\BreakPeriod;
use App\Enum\TimeSlotKind;
use App\Enum\Weekday;
use App\Guardia\BreakDutyRoster;
use App\Tests\Support\OwnsTheBreakZoneCatalogue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The break duty rota read as the screens read it: the recreo × weekday × zone grid, where it is short of
 * people, and the weighted equity count.
 *
 * Two rules are worth pinning down, and they are separate. A GUARDIA is one long recreo plus one short
 * one, on any two days, so it is counted as min(long, short) and the remainder shows up as halves. The
 * LOAD is the zone's weight per PLACE, because the centre said not every zone costs the same — two spells
 * in the patio have to outweigh two in the biblioteca.
 */
final class BreakDutyRosterTest extends KernelTestCase
{
    use OwnsTheBreakZoneCatalogue;

    private EntityManagerInterface $em;
    private BreakDutyRoster $roster;
    private AcademicYear $year;
    private BreakZone $patio;
    private BreakZone $biblioteca;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->roster = self::getContainer()->get(BreakDutyRoster::class);
        // La equidad suma los PESOS de las zonas, así que una sembrada de más falsea la media, la mediana
        // y el Gini: este escenario es dueño del catálogo.
        $this->emptyTheBreakZoneCatalogue($this->em);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-23'));
        $this->em->persist($this->year);

        // The centre's real recreos, and one teaching period to prove only breaks are picked up.
        $this->slot(0, '08:25', '09:20', TimeSlotKind::LECTIVE);
        $this->slot(3, '11:10', '11:35', TimeSlotKind::BREAK_TIME);
        $this->slot(6, '13:25', '13:35', TimeSlotKind::BREAK_TIME);

        // Two zones of different weight: the patio needs two people, the biblioteca one.
        $this->patio = $this->zone('Patio', 3, 2, 0);
        $this->biblioteca = $this->zone('Biblioteca', 1, 1, 1);

        $this->em->flush();
    }

    public function testGridPlacesEachDutyInItsWeekdayAndZoneAndReportsTheBreaks(): void
    {
        $ana = $this->user('Ana Patio Ruiz', 'ana.patio@educa.madrid.org');
        $this->duty($ana, Weekday::MONDAY, $this->patio, BreakPeriod::FIRST);

        $grid = $this->roster->grid($this->year);

        // Only the two recreos, in order, with their real times — never the lective period.
        self::assertCount(2, $grid['breaks']);
        self::assertSame('11:10–11:35', $grid['breaks'][0]->timeRange());
        self::assertSame('13:25–13:35', $grid['breaks'][1]->timeRange());

        $patio = (int) $this->patio->getId();
        self::assertCount(1, $grid['cells'][BreakPeriod::FIRST->value][Weekday::MONDAY->value][$patio]);
        self::assertSame([], $grid['cells'][BreakPeriod::SECOND->value][Weekday::MONDAY->value][$patio], 'the place is for ONE recreo, not both');
        self::assertSame([], $grid['cells'][BreakPeriod::FIRST->value][Weekday::TUESDAY->value][$patio], 'an empty cell still exists, so a template can index it');
    }

    public function testShortfallCountsThePeopleEachZoneStillNeeds(): void
    {
        $ana = $this->user('Ana Patio Ruiz', 'ana.patio@educa.madrid.org');
        $this->duty($ana, Weekday::MONDAY, $this->patio, BreakPeriod::FIRST);

        $grid = $this->roster->grid($this->year);

        $patio = (int) $this->patio->getId();
        $biblio = (int) $this->biblioteca->getId();
        // Patio needs 2 and has 1 at Monday's long recreo; every other cell is empty.
        self::assertSame(1, $grid['shortfall'][BreakPeriod::FIRST->value][Weekday::MONDAY->value][$patio]);
        self::assertSame(2, $grid['shortfall'][BreakPeriod::SECOND->value][Weekday::MONDAY->value][$patio], 'the short recreo of that day is still empty');
        self::assertSame(2, $grid['shortfall'][BreakPeriod::FIRST->value][Weekday::TUESDAY->value][$patio]);
        self::assertSame(1, $grid['shortfall'][BreakPeriod::FIRST->value][Weekday::MONDAY->value][$biblio]);
        // 2 recreos × 5 weekdays × (2 patio + 1 biblioteca) = 30 places, one of them filled.
        self::assertSame(29, $grid['missing']);
    }

    public function testEachRecreoReportsItsOwnPlacesAndWhatItIsMissing(): void
    {
        // La rejilla se dibuja UNA POR RECREO, así que su cabecera necesita el total de ese recreo: el de la
        // semana dice que falta algo pero no en cuál de las dos tablas.
        $ana = $this->user('Ana Patio Ruiz', 'ana.patio@educa.madrid.org');
        $this->duty($ana, Weekday::MONDAY, $this->patio, BreakPeriod::FIRST);

        $totals = $this->roster->grid($this->year)['periodTotals'];

        // 5 días × (2 del patio + 1 de la biblioteca) = 15 plazas en cada recreo.
        self::assertSame(15, $totals[BreakPeriod::FIRST->value]['places']);
        self::assertSame(1, $totals[BreakPeriod::FIRST->value]['filled']);
        self::assertSame(14, $totals[BreakPeriod::FIRST->value]['missing']);
        self::assertSame(15, $totals[BreakPeriod::SECOND->value]['missing'], 'el otro recreo sigue entero por cubrir');
        self::assertSame(0, $totals[BreakPeriod::SECOND->value]['extra']);
    }

    public function testTooManyPeopleInACellCountAsSurplusOfThatRecreo(): void
    {
        // Sobrar no es "completo": son plazas gastadas donde no hacen falta, y se cuentan aparte de las que
        // faltan para que la cabecera no pueda decir "completo" con la zona desbordada.
        $ana = $this->user('Ana Patio Ruiz', 'ana.patio@educa.madrid.org');
        $luis = $this->user('Luis Biblio Soto', 'luis.biblio@educa.madrid.org');
        $this->duty($ana, Weekday::MONDAY, $this->biblioteca, BreakPeriod::FIRST);
        $this->duty($luis, Weekday::MONDAY, $this->biblioteca, BreakPeriod::FIRST);

        $totals = $this->roster->grid($this->year)['periodTotals'];

        self::assertSame(1, $totals[BreakPeriod::FIRST->value]['extra'], 'la biblioteca pide 1 y hay 2');
        // 15 plazas menos la única que se cubre: la segunda persona de la biblioteca NO descuenta de lo que
        // falta en otra celda. Lo que sobra y lo que falta se cuentan por separado.
        self::assertSame(14, $totals[BreakPeriod::FIRST->value]['missing'], 'lo que sobra no tapa lo que falta');
    }

    public function testEquityAddsUpTheHeadlineFiguresOfTheWholeRota(): void
    {
        // Las dos cifras del ancla. Se suman aquí, donde están los datos, y no recorriendo las filas en la
        // plantilla — que es justo lo que un lector de datos existe para evitar.
        $ana = $this->user('Ana Patio Ruiz', 'ana.patio@educa.madrid.org');
        $luis = $this->user('Luis Biblio Soto', 'luis.biblio@educa.madrid.org');
        $this->duty($ana, Weekday::MONDAY, $this->patio, BreakPeriod::FIRST);
        $this->duty($ana, Weekday::TUESDAY, $this->patio, BreakPeriod::SECOND);
        $this->duty($luis, Weekday::MONDAY, $this->biblioteca, BreakPeriod::FIRST);

        $totals = $this->roster->equity($this->year)['totals'];

        self::assertSame(1, $totals['guardias'], 'solo Ana tiene un grande y un corto');
        self::assertSame(1, $totals['halves'], 'el recreo suelto de Luis sale como media');
    }

    public function testAProposalIsReadWholeSoTheScreenCannotMixItWithWhatIsStored(): void
    {
        // Con una propuesta en pantalla, la rejilla ES la propuesta: si el reparto se leyera de lo guardado,
        // la misma pantalla describiría dos cuadrantes distintos a la vez.
        $ana = $this->user('Ana Patio Ruiz', 'ana.patio@educa.madrid.org');
        $luis = $this->user('Luis Biblio Soto', 'luis.biblio@educa.madrid.org');
        $this->duty($ana, Weekday::MONDAY, $this->patio, BreakPeriod::FIRST);

        $overview = $this->roster->overviewFromProposal($this->year, [
            ['weekday' => Weekday::FRIDAY->value, 'period' => BreakPeriod::FIRST->value, 'zoneId' => (int) $this->biblioteca->getId(), 'teacherId' => (int) $luis->getId(), 'fixed' => false],
            ['weekday' => Weekday::FRIDAY->value, 'period' => BreakPeriod::SECOND->value, 'zoneId' => (int) $this->biblioteca->getId(), 'teacherId' => (int) $luis->getId(), 'fixed' => false],
        ]);

        // Lo de Ana está guardado y NO sale: lo que se está mirando es la propuesta.
        self::assertSame([], $overview['grid']['cells'][BreakPeriod::FIRST->value][Weekday::MONDAY->value][(int) $this->patio->getId()]);
        self::assertCount(1, $overview['grid']['cells'][BreakPeriod::FIRST->value][Weekday::FRIDAY->value][(int) $this->biblioteca->getId()]);
        self::assertSame(1, $overview['equity']['equity']['count'], 'el reparto es el de la propuesta, no el de lo guardado');
        self::assertSame('Luis Biblio Soto', $overview['equity']['rows'][0]['teacher']->getFullName());
        self::assertSame(1, $overview['equity']['totals']['guardias']);
    }

    public function testAnArchivedZoneLeavesTheGridButItsDutiesAreNotLost(): void
    {
        $ana = $this->user('Ana Patio Ruiz', 'ana.patio@educa.madrid.org');
        $this->duty($ana, Weekday::MONDAY, $this->biblioteca, BreakPeriod::FIRST);
        $this->biblioteca->setArchived(true);
        $this->em->flush();

        $grid = $this->roster->grid($this->year);

        self::assertCount(1, $grid['zones'], 'the archived zone is out of the grid');
        self::assertArrayNotHasKey((int) $this->biblioteca->getId(), $grid['cells'][BreakPeriod::FIRST->value][Weekday::MONDAY->value]);
        // The duty itself survives, which is why archiving (not deleting) is the gesture: the equity
        // reading still accounts for the turn that person did.
        self::assertSame(1, $this->roster->equity($this->year)['equity']['count']);
    }

    public function testAGuardiaIsALongRecreoPlusAShortOneEvenOnDifferentDays(): void
    {
        $ana = $this->user('Ana Patio Ruiz', 'ana.patio@educa.madrid.org');
        $luis = $this->user('Luis Biblio Soto', 'luis.biblio@educa.madrid.org');

        // Ana: the patio (weight 3) at both recreos of the same day → 1 guardia, load 6.
        $this->duty($ana, Weekday::MONDAY, $this->patio, BreakPeriod::FIRST);
        $this->duty($ana, Weekday::MONDAY, $this->patio, BreakPeriod::SECOND);
        // Luis: the biblioteca (weight 1) on DIFFERENT days, one long and one short → also 1 guardia.
        // That is the centre's rule, and the pairing is what the old model could not express.
        $this->duty($luis, Weekday::TUESDAY, $this->biblioteca, BreakPeriod::FIRST);
        $this->duty($luis, Weekday::THURSDAY, $this->biblioteca, BreakPeriod::SECOND);

        $equity = $this->roster->equity($this->year);

        self::assertSame(2, $equity['equity']['count'], 'only teachers on the rota are counted');
        // Heaviest first: two spells in the patio outweigh two in the library, which is the whole point
        // of weighing places rather than counting turns.
        self::assertSame('Ana Patio Ruiz', $equity['rows'][0]['teacher']->getFullName());
        self::assertSame(1, $equity['rows'][0]['guardias'], 'a long plus a short is one guardia');
        self::assertSame(0, $equity['rows'][0]['halves']);
        self::assertSame(6, $equity['rows'][0]['load'], 'the weight counts per place: two spells in the patio');
        self::assertSame(1, $equity['rows'][1]['guardias'], 'across days it is still one guardia');
        self::assertSame(2, $equity['rows'][1]['load']);
        self::assertSame(['Biblioteca'], $equity['rows'][1]['zones'], 'the same zone twice is listed once');
    }

    public function testAPlaceWithNoPartnerIsReportedAsAHalf(): void
    {
        // Two long recreos and no short one is NOT two guardias, and it is not one either: it is one
        // guardia's worth of work split so it cannot pair up. Rounding it away would hide exactly the
        // imbalance the rota is meant to expose.
        $ana = $this->user('Ana Patio Ruiz', 'ana.patio@educa.madrid.org');
        $this->duty($ana, Weekday::MONDAY, $this->biblioteca, BreakPeriod::FIRST);
        $this->duty($ana, Weekday::TUESDAY, $this->biblioteca, BreakPeriod::FIRST);

        $equity = $this->roster->equity($this->year);

        self::assertSame(0, $equity['rows'][0]['guardias']);
        self::assertSame(2, $equity['rows'][0]['halves'], 'two long recreos waiting for a short one');
        self::assertSame(2, $equity['rows'][0]['places']);
    }

    public function testSomebodyCanWatchDifferentZonesAtEachRecreoOfTheSameDay(): void
    {
        // Flatly impossible under the old shape (one row per teacher and weekday), and something the
        // centre asked for by name.
        $ana = $this->user('Ana Patio Ruiz', 'ana.patio@educa.madrid.org');
        $this->duty($ana, Weekday::MONDAY, $this->patio, BreakPeriod::FIRST);
        $this->duty($ana, Weekday::MONDAY, $this->biblioteca, BreakPeriod::SECOND);

        $equity = $this->roster->equity($this->year);

        self::assertSame(1, $equity['rows'][0]['guardias']);
        self::assertSame(4, $equity['rows'][0]['load'], 'patio 3 + biblioteca 1');
        self::assertSame(['Patio', 'Biblioteca'], $equity['rows'][0]['zones']);
    }

    public function testEquityIsEmptyButUsableWhenNobodyIsOnTheRota(): void
    {
        $equity = $this->roster->equity($this->year);

        self::assertSame([], $equity['rows']);
        self::assertSame(0, $equity['equity']['count'], 'a course with no rota reads as zero, not as a crash');
    }

    /**
     * Persists a period of the course's marco horario.
     *
     * @param int          $index the period index
     * @param string       $from  start time, "HH:MM"
     * @param string       $to    end time, "HH:MM"
     * @param TimeSlotKind $kind  teaching period or recreo
     */
    private function slot(int $index, string $from, string $to, TimeSlotKind $kind): void
    {
        $this->em->persist((new TimeSlot())
            ->setAcademicYear($this->year)
            ->setSlotIndex($index)
            ->setStartsAt(new \DateTimeImmutable($from))
            ->setEndsAt(new \DateTimeImmutable($to))
            ->setKind($kind));
    }

    /**
     * Persists a break zone.
     *
     * @param string $name     the zone name
     * @param int    $weight   how demanding it is
     * @param int    $required how many teachers it needs each recreo
     * @param int    $order    display order
     *
     * @return BreakZone the persisted zone
     */
    private function zone(string $name, int $weight, int $required, int $order): BreakZone
    {
        $zone = (new BreakZone())->setName($name)->setWeight($weight)->setRequiredTeachers($required)->setSortOrder($order);
        $this->em->persist($zone);

        return $zone;
    }

    /**
     * Persists one rota line and flushes it.
     *
     * @param User                $teacher  the teacher on duty
     * @param Weekday             $weekday  the weekday
     * @param BreakZone           $zone     the zone to watch
     * @param BreakPeriod $period which recreo the place is for
     */
    private function duty(User $teacher, Weekday $weekday, BreakZone $zone, BreakPeriod $period): void
    {
        $this->em->persist((new BreakDutyAssignment())
            ->setAcademicYear($this->year)
            ->setTeacher($teacher)
            ->setWeekday($weekday)
            ->setZone($zone)
            ->setPeriod($period));
        $this->em->flush();
    }

    /**
     * Persists a user with a name and e-mail.
     *
     * @param string $name  the full name
     * @param string $email the e-mail
     *
     * @return User the persisted user
     */
    private function user(string $name, string $email): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
