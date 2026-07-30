<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakZone;
use App\Entity\TimeSlot;
use App\Entity\User;
use App\Enum\BreakPeriodCoverage;
use App\Enum\TimeSlotKind;
use App\Enum\Weekday;
use App\Guardia\BreakDutyRoster;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The break duty rota read as the screens read it: the weekday × zone grid, where it is short of people,
 * and the weighted equity count.
 *
 * The load rule is the one worth pinning down: the centre counts covering BOTH recreos of a day as ONE
 * guardia, and weighs zones differently, so the counter adds the zone's weight once per duty — not once
 * per recreo, and not one point per turn.
 */
final class BreakDutyRosterTest extends KernelTestCase
{
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
        $this->duty($ana, Weekday::MONDAY, $this->patio, BreakPeriodCoverage::BOTH);

        $grid = $this->roster->grid($this->year);

        // Only the two recreos, in order, with their real times — never the lective period.
        self::assertCount(2, $grid['breaks']);
        self::assertSame('11:10–11:35', $grid['breaks'][0]->timeRange());
        self::assertSame('13:25–13:35', $grid['breaks'][1]->timeRange());

        self::assertCount(1, $grid['cells'][Weekday::MONDAY->value][(int) $this->patio->getId()]);
        self::assertSame([], $grid['cells'][Weekday::TUESDAY->value][(int) $this->patio->getId()], 'an empty cell still exists, so a template can index it');
    }

    public function testShortfallCountsThePeopleEachZoneStillNeeds(): void
    {
        $ana = $this->user('Ana Patio Ruiz', 'ana.patio@educa.madrid.org');
        $this->duty($ana, Weekday::MONDAY, $this->patio, BreakPeriodCoverage::BOTH);

        $grid = $this->roster->grid($this->year);

        // Patio needs 2 and has 1 on Monday; every other cell is empty.
        self::assertSame(1, $grid['shortfall'][Weekday::MONDAY->value][(int) $this->patio->getId()]);
        self::assertSame(2, $grid['shortfall'][Weekday::TUESDAY->value][(int) $this->patio->getId()]);
        self::assertSame(1, $grid['shortfall'][Weekday::MONDAY->value][(int) $this->biblioteca->getId()]);
        // 5 weekdays × (2 patio + 1 biblioteca) = 15 posts, one of them filled.
        self::assertSame(14, $grid['missing']);
    }

    public function testAnArchivedZoneLeavesTheGridButItsDutiesAreNotLost(): void
    {
        $ana = $this->user('Ana Patio Ruiz', 'ana.patio@educa.madrid.org');
        $this->duty($ana, Weekday::MONDAY, $this->biblioteca, BreakPeriodCoverage::FIRST);
        $this->biblioteca->setArchived(true);
        $this->em->flush();

        $grid = $this->roster->grid($this->year);

        self::assertCount(1, $grid['zones'], 'the archived zone is out of the grid');
        self::assertArrayNotHasKey((int) $this->biblioteca->getId(), $grid['cells'][Weekday::MONDAY->value]);
        // The duty itself survives, which is why archiving (not deleting) is the gesture: the equity
        // reading still accounts for the turn that person did.
        self::assertSame(1, $this->roster->equity($this->year)['equity']['count']);
    }

    public function testCoveringBothRecreosCountsAsASingleWeightedGuardia(): void
    {
        $ana = $this->user('Ana Patio Ruiz', 'ana.patio@educa.madrid.org');
        $luis = $this->user('Luis Biblio Soto', 'luis.biblio@educa.madrid.org');

        // Ana: one duty spanning BOTH recreos of the patio (weight 3) → load 3, not 6.
        $this->duty($ana, Weekday::MONDAY, $this->patio, BreakPeriodCoverage::BOTH);
        // Luis: two duties on different days, biblioteca (weight 1) → load 2.
        $this->duty($luis, Weekday::TUESDAY, $this->biblioteca, BreakPeriodCoverage::FIRST);
        $this->duty($luis, Weekday::THURSDAY, $this->biblioteca, BreakPeriodCoverage::SECOND);

        $equity = $this->roster->equity($this->year);

        self::assertSame(2, $equity['equity']['count'], 'only teachers on the rota are counted');
        // Heaviest first: Ana's single two-recreo patio turn outweighs Luis's two library turns.
        self::assertSame('Ana Patio Ruiz', $equity['rows'][0]['teacher']->getFullName());
        self::assertSame(1, $equity['rows'][0]['duties'], 'both recreos of a day are ONE guardia');
        self::assertSame(3, $equity['rows'][0]['load'], 'the zone weight counts once, not per recreo');
        self::assertSame(2, $equity['rows'][1]['duties']);
        self::assertSame(2, $equity['rows'][1]['load']);
        self::assertSame(['Biblioteca'], $equity['rows'][1]['zones'], 'the same zone twice is listed once');
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
     * @param BreakPeriodCoverage $coverage which recreos it spans
     */
    private function duty(User $teacher, Weekday $weekday, BreakZone $zone, BreakPeriodCoverage $coverage): void
    {
        $this->em->persist((new BreakDutyAssignment())
            ->setAcademicYear($this->year)
            ->setTeacher($teacher)
            ->setWeekday($weekday)
            ->setZone($zone)
            ->setPeriods($coverage));
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
