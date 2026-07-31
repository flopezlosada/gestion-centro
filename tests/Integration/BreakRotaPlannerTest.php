<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakDutyGap;
use App\Entity\BreakZone;
use App\Entity\GuardiaQuota;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\BreakDutySource;
use App\Enum\BreakPeriod;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Guardia\BreakRotaPlanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The database side of the break rota engine: what it reads to propose, and — the part with teeth — what
 * publishing does to what is already there.
 */
final class BreakRotaPlannerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BreakRotaPlanner $planner;
    private AcademicYear $year;
    private BreakZone $patio;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->planner = self::getContainer()->get(BreakRotaPlanner::class);

        $this->year = (new AcademicYear())
            ->setSchoolYear('2025-2026')
            ->setTerm1Start(new \DateTimeImmutable('2025-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2025-12-19'))
            ->setTerm2Start(new \DateTimeImmutable('2026-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2026-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2026-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2026-06-23'));
        $this->em->persist($this->year);

        // One zone needing one person: the week is then 10 places (5 weekdays × 2 recreos), small enough
        // to reason about by hand.
        $this->patio = (new BreakZone())->setName('Patio')->setWeight(3)->setRequiredTeachers(1)->setSortOrder(0);
        $this->em->persist($this->patio);
        $this->em->flush();
    }

    public function testPublishingWritesTheProposalAsEnginePlaces(): void
    {
        $this->staff('Ana Recreo Ruiz', 'ana.recreo@educa.madrid.org', 2);

        $proposal = $this->planner->propose($this->year);
        $written = $this->planner->publish($this->year, $proposal->places);

        self::assertSame(4, $written, 'a quota of two is two long places and two short ones');
        self::assertCount(4, $this->places());
        foreach ($this->places() as $place) {
            self::assertSame(BreakDutySource::ENGINE, $place->getSource());
        }
    }

    public function testRepublishingKeepsTheRecordOfRecreosThatWentUnwatched(): void
    {
        // The one that matters. Every BreakDutyGap hangs off its place with ON DELETE CASCADE, so a
        // publish that wiped and rewrote the engine's places would take months of "this recreo went
        // unwatched" with it — silently, and precisely when somebody re-publishes after nudging a quota.
        $this->staff('Ana Recreo Ruiz', 'ana.recreo@educa.madrid.org', 2);
        $this->planner->publish($this->year, $this->planner->propose($this->year)->places);

        $place = $this->places()[0];
        $gap = (new BreakDutyGap())->setAssignment($place)->setDate(new \DateTimeImmutable('2025-11-03'));
        $this->em->persist($gap);
        $this->em->flush();
        $gapId = $gap->getId();

        // Same inputs, same proposal: nothing actually changes, so nothing should be destroyed.
        $this->planner->publish($this->year, $this->planner->propose($this->year)->places);

        $this->em->clear();
        self::assertNotNull(
            $this->em->getRepository(BreakDutyGap::class)->find($gapId),
            'the unwatched-recreo record was destroyed by re-publishing',
        );
    }

    public function testPublishingLeavesHandAddedPlacesAloneAndDoesNotDuplicateThem(): void
    {
        // The patios dirigidos the equipo directivo organises by hand: honoured, not replaced, and not
        // written a second time under the engine's name.
        $ana = $this->staff('Ana Recreo Ruiz', 'ana.recreo@educa.madrid.org', 1);
        $byHand = (new BreakDutyAssignment())
            ->setAcademicYear($this->year)
            ->setTeacher($ana)
            ->setWeekday(Weekday::MONDAY)
            ->setZone($this->patio)
            ->setPeriod(BreakPeriod::FIRST)
            ->setSource(BreakDutySource::MANUAL);
        $this->em->persist($byHand);
        $this->em->flush();
        $manualId = $byHand->getId();

        $this->planner->publish($this->year, $this->planner->propose($this->year)->places);

        $this->em->clear();
        $manual = $this->em->getRepository(BreakDutyAssignment::class)->find($manualId);
        self::assertNotNull($manual, 'the hand-added place was deleted');
        self::assertSame(BreakDutySource::MANUAL, $manual->getSource());

        $duplicates = 0;
        $seen = [];
        foreach ($this->places() as $place) {
            $key = $place->getTeacher()->getId().':'.$place->getWeekday()->value.':'.$place->getPeriod()->value;
            if (isset($seen[$key])) {
                ++$duplicates;
            }
            $seen[$key] = true;
        }
        self::assertSame(0, $duplicates, 'somebody ended up twice at one recreo of a day');
    }

    public function testAPlaceThatDropsOutOfTheProposalIsRemoved(): void
    {
        // The other half of the diff: keeping unchanged places must not mean keeping stale ones.
        $ana = $this->staff('Ana Recreo Ruiz', 'ana.recreo@educa.madrid.org', 2);
        $this->planner->publish($this->year, $this->planner->propose($this->year)->places);
        self::assertCount(4, $this->places());

        // Drop her quota to one: the week now wants half as many places from her.
        $quota = $this->em->getRepository(GuardiaQuota::class)->findOneBy(['teacher' => $ana->getId()]);
        self::assertNotNull($quota);
        $quota->setBreakDuties(1);
        $this->em->flush();

        $this->planner->publish($this->year, $this->planner->propose($this->year)->places);

        self::assertCount(2, $this->places(), 'the places she no longer holds are gone');
    }

    public function testAQuotaOfZeroPutsNobodyOnTheRota(): void
    {
        $this->staff('Exenta Directiva', 'exenta@educa.madrid.org', 0);

        $this->planner->publish($this->year, $this->planner->propose($this->year)->places);

        self::assertSame([], $this->places());
    }

    /**
     * A teacher with a timetable in the course and a recreo quota.
     *
     * The timetable entry is what makes them staff of this course at all — the planner reads its
     * candidates from there, not from the whole user table.
     *
     * @param string $name  full name
     * @param string $email e-mail
     * @param int    $quota recreo guardias they take on
     *
     * @return User the persisted teacher
     */
    private function staff(string $name, string $email, int $quota): User
    {
        $user = (new User())->setFullName($name)->setEmail($email);
        $this->em->persist($user);

        $this->em->persist((new ScheduleEntry())
            ->setAcademicYear($this->year)
            ->setTeacher($user)
            ->setWeekday(Weekday::MONDAY)
            ->setSlotIndex(0)
            ->setStartsAt(new \DateTimeImmutable('08:25'))
            ->setEndsAt(new \DateTimeImmutable('09:20'))
            ->setKind(ScheduleActivityKind::LECTIVE)
            ->setGroupName('1ºA'));

        $this->em->persist((new GuardiaQuota())
            ->setAcademicYear($this->year)
            ->setTeacher($user)
            ->setBreakDuties($quota));

        $this->em->flush();

        return $user;
    }

    /**
     * The engine's places in the course, freshly read.
     *
     * @return list<BreakDutyAssignment> the places
     */
    private function places(): array
    {
        $this->em->clear();

        return $this->em->getRepository(BreakDutyAssignment::class)->findBy([
            'academicYear' => $this->year->getId(),
            'source' => BreakDutySource::ENGINE,
        ]);
    }
}
