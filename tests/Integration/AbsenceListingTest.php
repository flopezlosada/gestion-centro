<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Absence;
use App\Entity\User;
use App\Enum\Weekday;
use App\Guardia\AbsenceListing;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The week the administrative staff download to record absences in RAICES: Monday to Friday, every day
 * listed, nothing from the weeks around it, and nothing older than the plazo even if the purge has not run.
 * Against the real database, because the week's edges are in the query.
 */
final class AbsenceListingTest extends KernelTestCase
{
    /** A Monday, and «today» two days later: the whole week is inside the default plazo of four weeks. */
    private const string MONDAY = '2026-11-09';
    private const string TODAY = '2026-11-11';

    private EntityManagerInterface $em;
    private AbsenceListing $listing;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->listing = self::getContainer()->get(AbsenceListing::class);
    }

    /** Monday to Friday, by day and within a day by name; nothing from the weekend nor the weeks around. */
    public function testListsMondayToFridayByDayAndNameAndNothingAround(): void
    {
        $zoe = $this->teacher('Zoe Zamora');
        $ana = $this->teacher('Ana Alonso');
        $this->absence($zoe, '2026-11-09', [0]);
        $this->absence($ana, '2026-11-09', [1, 2]);
        $this->absence($ana, '2026-11-13', [5]);
        $this->absence($ana, '2026-11-06', [0]); // the Friday before
        $this->absence($zoe, '2026-11-14', [0]); // the Saturday of the week
        $this->absence($zoe, '2026-11-16', [0]); // the Monday after

        // Any day of the week gives the same week: Wednesday here.
        $week = $this->listing->week(new \DateTimeImmutable('2026-11-11'), new \DateTimeImmutable(self::TODAY));

        self::assertSame(self::MONDAY, $week['monday']->format('Y-m-d'));
        self::assertSame('2026-11-13', $week['friday']->format('Y-m-d'));
        self::assertSame(
            ['2026-11-09' => ['Ana Alonso', 'Zoe Zamora'], '2026-11-10' => [], '2026-11-11' => [], '2026-11-12' => [], '2026-11-13' => ['Ana Alonso']],
            $this->namesByDay($week['days']),
        );
        self::assertSame([1, 2], $week['days'][0]['rows'][0]['absence']->getSlotIndexes());
        self::assertFalse($week['days'][0]['beforeKept']);
    }

    /** The plazo cuts in the query: what the purge has not deleted yet is not listed either. */
    public function testDaysOlderThanThePlazoComeOutFlaggedAndEmptyEvenBeforeThePurge(): void
    {
        // Four weeks back from Wednesday 9 Dec is Wednesday 11 Nov: Monday and Tuesday of that week are out.
        $teacher = $this->teacher('Ana Alonso');
        $this->absence($teacher, '2026-11-10', [0]);
        $this->absence($teacher, '2026-11-11', [0]);

        $week = $this->listing->week(new \DateTimeImmutable(self::MONDAY), new \DateTimeImmutable('2026-12-09'));

        self::assertSame([true, true, false, false, false], array_map(static fn (array $day): bool => $day['beforeKept'], $week['days']));
        self::assertSame(
            ['2026-11-09' => [], '2026-11-10' => [], '2026-11-11' => ['Ana Alonso'], '2026-11-12' => [], '2026-11-13' => []],
            $this->namesByDay($week['days']),
        );
    }

    /** «Nobody» is an answer too: an empty week still has its five days, named. */
    public function testAWeekWithNobodyAwayStillListsItsFiveDays(): void
    {
        $week = $this->listing->week(new \DateTimeImmutable('2026-11-15'), new \DateTimeImmutable(self::TODAY));

        // A Sunday belongs to the week that started the Monday before.
        self::assertSame(self::MONDAY, $week['monday']->format('Y-m-d'));
        self::assertCount(5, $week['days']);
        self::assertSame([[], [], [], [], []], array_map(static fn (array $day): array => $day['rows'], $week['days']));
        self::assertSame(Weekday::schoolWeek(), array_map(static fn (array $day): Weekday => $day['weekday'], $week['days']));
    }

    /**
     * The names of each day, keyed by date, to compare a whole week at a glance.
     *
     * @param list<array{date: \DateTimeImmutable, weekday: Weekday, beforeKept: bool, rows: list<array{absence: Absence, slotTimes: array<int, mixed>}>}> $days the week's days
     *
     * @return array<string, list<string>> date → names, in order
     */
    private function namesByDay(array $days): array
    {
        $names = [];
        foreach ($days as $day) {
            $names[$day['date']->format('Y-m-d')] = array_map(static fn (array $row): string => $row['absence']->getAbsentTeacher()->getFullName(), $day['rows']);
        }

        return $names;
    }

    /**
     * Persists a teacher.
     *
     * @param string $name the full name
     *
     * @return User the persisted teacher
     */
    private function teacher(string $name): User
    {
        $teacher = (new User())->setFullName($name)->setEmail('t-'.uniqid().'@centro.test');
        $this->em->persist($teacher);

        return $teacher;
    }

    /**
     * Persists an absence and flushes, so the listing reads it.
     *
     * @param User      $teacher the absent teacher
     * @param string    $date    the day, as Y-m-d
     * @param list<int> $slots   the period indexes it spans
     */
    private function absence(User $teacher, string $date, array $slots): void
    {
        $this->em->persist((new Absence())->setAbsentTeacher($teacher)->setDate(new \DateTimeImmutable($date))->addSlotIndexes($slots));
        $this->em->flush();
    }
}
