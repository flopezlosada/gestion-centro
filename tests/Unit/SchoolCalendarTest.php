<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AcademicYear;
use App\Entity\NonLectiveDay;
use App\Repository\AcademicYearRepository;
use App\Repository\NonLectiveDayRepository;
use App\Service\SchoolCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A day is teaching (lective) when it is not a weekend, not in a break between two terms of its course
 * and not a registered non-teaching day. The repositories are stubbed so these stay pure (no database).
 */
final class SchoolCalendarTest extends TestCase
{
    public function testWeekendIsNotLective(): void
    {
        $repo = $this->createMock(NonLectiveDayRepository::class);
        // Weekends short-circuit before the repository is even consulted.
        $repo->expects(self::never())->method('existsOn');
        $calendar = new SchoolCalendar($repo, $this->withoutCourses());

        self::assertFalse($calendar->isLective(new \DateTimeImmutable('2026-07-11')), 'Saturday is not lective');
        self::assertFalse($calendar->isLective(new \DateTimeImmutable('2026-07-12')), 'Sunday is not lective');
    }

    public function testRegisteredHolidayIsNotLective(): void
    {
        $repo = $this->createMock(NonLectiveDayRepository::class);
        $repo->method('existsOn')->willReturn(true);
        $calendar = new SchoolCalendar($repo, $this->withoutCourses());

        // A Monday that is registered as a non-teaching day.
        self::assertFalse($calendar->isLective(new \DateTimeImmutable('2026-07-13')));
    }

    public function testPlainWeekdayIsLective(): void
    {
        $calendar = new SchoolCalendar($this->withoutHolidays(), $this->withoutCourses());

        // A Monday that is not registered anywhere, in a course that has not been created.
        self::assertTrue($calendar->isLective(new \DateTimeImmutable('2026-07-13')));
    }

    public function testIsWeekendRecognisesSaturdayAndSunday(): void
    {
        $calendar = new SchoolCalendar($this->createMock(NonLectiveDayRepository::class), $this->withoutCourses());

        self::assertTrue($calendar->isWeekend(new \DateTimeImmutable('2026-07-11')), 'Saturday');
        self::assertTrue($calendar->isWeekend(new \DateTimeImmutable('2026-07-12')), 'Sunday');
        self::assertFalse($calendar->isWeekend(new \DateTimeImmutable('2026-07-13')), 'Monday');
    }

    /**
     * The breaks between terms come from the course's dates, with nothing typed in: the weekdays strictly
     * between the end of a term and the start of the next one.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function breakDays(): iterable
    {
        yield 'the day after the first term' => ['2026-12-23', 'Vacaciones de Navidad'];
        yield 'between Christmas and New Year' => ['2026-12-28', 'Vacaciones de Navidad'];
        yield 'the day before the second term, in the next calendar year' => ['2027-01-08', 'Vacaciones de Navidad'];
        yield 'the day after the second term' => ['2027-03-19', 'Vacaciones de Semana Santa'];
        yield 'the day before the third term' => ['2027-03-29', 'Vacaciones de Semana Santa'];
    }

    #[DataProvider('breakDays')]
    public function testADayBetweenTwoTermsIsABreakAndNotLective(string $day, string $break): void
    {
        $calendar = new SchoolCalendar($this->withoutHolidays(), $this->withCourse());

        self::assertSame($break, $calendar->breakOn(new \DateTimeImmutable($day)));
        self::assertFalse($calendar->isLective(new \DateTimeImmutable($day)));
    }

    /**
     * The first and last day of each term have classes, and so do the edges of the course: before the
     * first term and after the last one staff work and the centre's deadlines fall («Antes del 23 de
     * junio»), so those are not breaks.
     *
     * @return iterable<string, array{string}>
     */
    public static function lectiveDays(): iterable
    {
        yield 'the last day of the first term' => ['2026-12-22'];
        yield 'the first day of the second term' => ['2027-01-11'];
        yield 'the last day of the second term' => ['2027-03-18'];
        yield 'the first day of the third term' => ['2027-03-30'];
        yield 'early September, before the first term' => ['2026-09-01'];
        yield 'late June, after the last term' => ['2027-06-23'];
        yield 'a time of day on a term day' => ['2027-01-11 18:30'];
    }

    #[DataProvider('lectiveDays')]
    public function testTermDaysAndTheEdgesOfTheCourseAreLective(string $day): void
    {
        $calendar = new SchoolCalendar($this->withoutHolidays(), $this->withCourse());

        self::assertNull($calendar->breakOn(new \DateTimeImmutable($day)));
        self::assertTrue($calendar->isLective(new \DateTimeImmutable($day)));
    }

    public function testADayOfACourseNotYetCreatedIsInNoBreak(): void
    {
        $calendar = new SchoolCalendar($this->withoutHolidays(), $this->withoutCourses());

        self::assertNull($calendar->breakOn(new \DateTimeImmutable('2026-12-28')));
        self::assertTrue($calendar->isLective(new \DateTimeImmutable('2026-12-28')), 'without term dates only weekends and registered days count');
    }

    public function testTheCourseIsLookedUpOncePerSchoolYear(): void
    {
        $years = $this->createMock(AcademicYearRepository::class);
        $years->expects(self::exactly(2))->method('findBySchoolYear')->willReturnCallback(
            static fn (string $schoolYear): ?AcademicYear => '2026-2027' === $schoolYear ? self::course() : null,
        );
        $calendar = new SchoolCalendar($this->withoutHolidays(), $years);

        foreach (['2026-12-23', '2026-12-28', '2027-01-08', '2027-03-19', '2027-07-05', '2027-09-14'] as $day) {
            $calendar->breakOn(new \DateTimeImmutable($day));
        }
        // A missing course is remembered as well, not looked up again.
        $calendar->breakOn(new \DateTimeImmutable('2027-10-04'));
    }

    public function testResetForgetsTheCourses(): void
    {
        $years = $this->createMock(AcademicYearRepository::class);
        $years->expects(self::exactly(2))->method('findBySchoolYear')->willReturn(self::course());
        $calendar = new SchoolCalendar($this->withoutHolidays(), $years);

        $calendar->breakOn(new \DateTimeImmutable('2026-12-28'));
        $calendar->reset();
        $calendar->breakOn(new \DateTimeImmutable('2026-12-28'));
    }

    public function testADeadlineInABreakMovesBackToTheLastDayOfTheTerm(): void
    {
        $calendar = new SchoolCalendar($this->withoutHolidays(), $this->withCourse());

        self::assertSame('2026-12-22', $calendar->onOrBeforeLectiveDay(new \DateTimeImmutable('2027-01-04'))->format('Y-m-d'));
    }

    /**
     * The teaching days of a stretch: weekends, the Christmas break and a registered day are left out, and
     * the registered days are read ONCE for the whole stretch — a fixed booking walks a whole course, and a
     * query per day would be one per day of it.
     */
    public function testTheTeachingDaysOfAStretchLeaveOutWeekendsBreaksAndRegisteredDays(): void
    {
        $holidays = $this->createMock(NonLectiveDayRepository::class);
        $holidays->expects(self::never())->method('existsOn');
        $holidays->expects(self::once())->method('findBetween')
            ->willReturn([(new NonLectiveDay())->setDate(new \DateTimeImmutable('2026-12-21'))->setDescription('Día del centro')]);
        $calendar = new SchoolCalendar($holidays, $this->withCourse());

        // Del viernes 18/12 al lunes 11/01: el finde, el 21 registrado, el 22 último día del trimestre, y
        // Navidad hasta el 10 de enero.
        $days = array_map(
            static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'),
            $calendar->lectiveDaysBetween(new \DateTimeImmutable('2026-12-18 13:00'), new \DateTimeImmutable('2027-01-11')),
        );

        self::assertSame(['2026-12-18', '2026-12-22', '2027-01-11'], $days);
    }

    /**
     * The centre's 2026-2027 course as it is in production: Christmas from 23 December to 10 January and
     * Easter from 19 to 29 March.
     */
    private static function course(): AcademicYear
    {
        return (new AcademicYear())
            ->setSchoolYear('2026-2027')
            ->setTerm1Start(new \DateTimeImmutable('2026-09-08'))
            ->setTerm1End(new \DateTimeImmutable('2026-12-22'))
            ->setTerm2Start(new \DateTimeImmutable('2027-01-11'))
            ->setTerm2End(new \DateTimeImmutable('2027-03-18'))
            ->setTerm3Start(new \DateTimeImmutable('2027-03-30'))
            ->setTerm3End(new \DateTimeImmutable('2027-06-18'));
    }

    /**
     * A course repository that knows only the 2026-2027 course.
     */
    private function withCourse(): AcademicYearRepository
    {
        $years = $this->createMock(AcademicYearRepository::class);
        $years->method('findBySchoolYear')->willReturnCallback(
            static fn (string $schoolYear): ?AcademicYear => '2026-2027' === $schoolYear ? self::course() : null,
        );

        return $years;
    }

    /**
     * A course repository with no course created.
     */
    private function withoutCourses(): AcademicYearRepository
    {
        $years = $this->createMock(AcademicYearRepository::class);
        $years->method('findBySchoolYear')->willReturn(null);

        return $years;
    }

    /**
     * A non-teaching day repository with no day registered.
     */
    private function withoutHolidays(): NonLectiveDayRepository
    {
        $repo = $this->createMock(NonLectiveDayRepository::class);
        $repo->method('existsOn')->willReturn(false);

        return $repo;
    }
}
