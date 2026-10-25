<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Booking;
use App\Entity\Department;
use App\Entity\Material;
use App\Entity\RecurringBooking;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\Weekday;
use PHPUnit\Framework\TestCase;

/**
 * A fixed booking: what it holds, for whom, and who may free one of its days. The rule that matters is the
 * last one — a department's trolley freed by anybody in the department is a trolley nobody can count on, so
 * it is the holder, the head of THAT department or whoever made it, and nobody else.
 */
final class RecurringBookingTest extends TestCase
{
    public function testThePatternIsStoredSortedAndWithoutEmptyDays(): void
    {
        $series = $this->series(new Department(), [5 => [4, 3, 3], 1 => [2, 1], 3 => []]);

        self::assertSame([1 => [1, 2], 5 => [3, 4]], $series->getPattern());
        self::assertSame([3, 4], $series->slotsOn(Weekday::FRIDAY));
        self::assertSame([], $series->slotsOn(Weekday::WEDNESDAY));
    }

    public function testASeriesWithoutAnyPeriodCannotBeMade(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->series(new Department(), [1 => []]);
    }

    public function testASeriesCannotEndBeforeItStarts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RecurringBooking($this->person('Carol'), $this->cart(), new Department(), new \DateTimeImmutable('2026-11-02'), new \DateTimeImmutable('2026-11-01'), [1 => [1]], 'Tecnología');
    }

    public function testThePersonWhoHoldsItAndWhoMadeItMayFreeADay(): void
    {
        $carol = $this->person('Carol');
        $mar = $this->person('Mar');
        $series = new RecurringBooking($carol, $this->cart(), $mar, new \DateTimeImmutable('2026-11-02'), new \DateTimeImmutable('2027-06-18'), [1 => [1, 2]], 'Programación');

        self::assertTrue($series->mayBeReleasedBy($mar), 'quien la tiene');
        self::assertTrue($series->mayBeReleasedBy($carol), 'quien la creó');
        self::assertFalse($series->mayBeReleasedBy($this->person('Otra')));
        self::assertSame('Mar Test', $series->holderName());
    }

    public function testOnlyTheHeadOfTheHoldingDepartmentMayFreeADayOfItsBooking(): void
    {
        $technology = (new Department())->setName('Tecnología');
        $maths = (new Department())->setName('Matemáticas');
        $head = (new Role())->setCode('head_dept')->setName('Jefatura de departamento');
        $series = $this->series($technology, [1 => [4]]);

        $technologyHead = $this->person('Jefa')->setUnit($technology)->addAssignedRole($head);
        $technologyTeacher = $this->person('Profe')->setUnit($technology);
        $mathsHead = $this->person('Jefe')->setUnit($maths)->addAssignedRole($head);

        self::assertTrue($series->mayBeReleasedBy($technologyHead));
        self::assertFalse($series->mayBeReleasedBy($technologyTeacher), 'ser del departamento no basta');
        self::assertFalse($series->mayBeReleasedBy($mathsHead), 'la jefatura de OTRO departamento tampoco');
        self::assertSame('Tecnología', $series->holderName());
    }

    /** Un titular dado de baja (SET NULL) no deja la serie sin nombre ni abierta a cualquiera. */
    public function testAHolderThatNoLongerExistsIsSaidAndFreesNothing(): void
    {
        $series = $this->series(new Department(), [1 => [1]]);
        (new \ReflectionProperty(RecurringBooking::class, 'holderDepartment'))->setValue($series, null);

        self::assertSame('Titular dado de baja', $series->holderName());
        self::assertFalse($series->mayBeReleasedBy($this->person('Cualquiera')->setUnit(null)));
    }

    public function testEndingOnlyShortensWithinItsOwnDates(): void
    {
        $series = $this->series(new Department(), [1 => [1]]);
        $series->endOn(new \DateTimeImmutable('2026-12-04 15:30'));
        self::assertSame('2026-12-04 00:00', $series->getEndsOn()->format('Y-m-d H:i'));

        $this->expectException(\InvalidArgumentException::class);
        $series->endOn(new \DateTimeImmutable('2026-10-01'));
    }

    /** Cada día de la serie es una reserva más, con lo que tiene la serie y su titular. */
    public function testEachDayIsABookingOfTheSameThingHeldByTheSeriesHolder(): void
    {
        $carol = $this->person('Carol');
        $mar = $this->person('Mar');
        $cart = $this->cart();
        $series = new RecurringBooking($carol, $cart, $mar, new \DateTimeImmutable('2026-11-02'), new \DateTimeImmutable('2027-06-18'), [1 => [1]], 'Programación');

        $booking = Booking::forSeries($series, $carol, new \DateTimeImmutable('2026-11-09'), 1);

        self::assertSame($cart, $booking->getMaterial());
        self::assertSame('Programación', $booking->getPurpose());
        self::assertSame('Mar Test', $booking->holderName(), 'la tiene Mar, aunque la tecleara Carol');
        self::assertTrue($booking->mayBeReleasedBy($mar));
        self::assertFalse($booking->isOwnedBy($mar), 'no es una reserva suelta suya');
    }

    /**
     * @param array<int, list<int>> $pattern
     */
    private function series(Department $holder, array $pattern): RecurringBooking
    {
        return new RecurringBooking($this->person('Carol'), $this->cart(), $holder, new \DateTimeImmutable('2026-11-02'), new \DateTimeImmutable('2027-06-18'), $pattern, 'Tecnología');
    }

    private function person(string $name): User
    {
        return (new User())->setFullName($name.' Test')->setEmail(strtolower($name).'@centro.test');
    }

    private function cart(): Material
    {
        return (new Material())->setName('Dora Maar');
    }
}
