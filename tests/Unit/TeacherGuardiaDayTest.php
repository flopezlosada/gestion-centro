<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\GuardiaCover;
use App\Entity\User;
use App\Guardia\TeacherGuardiaDay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one answer to "which is your next guardia, and which are already over" — shared by "Mis guardias"
 * and the home hero, which used to work it out separately.
 */
final class TeacherGuardiaDayTest extends TestCase
{
    private TeacherGuardiaDay $day;

    protected function setUp(): void
    {
        $this->day = new TeacherGuardiaDay();
    }

    /**
     * @return array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}>
     */
    private function slotTimes(): array
    {
        return [
            0 => ['startsAt' => new \DateTimeImmutable('2026-03-10 08:25'), 'endsAt' => new \DateTimeImmutable('2026-03-10 09:20')],
            1 => ['startsAt' => new \DateTimeImmutable('2026-03-10 09:20'), 'endsAt' => new \DateTimeImmutable('2026-03-10 10:15')],
            2 => ['startsAt' => new \DateTimeImmutable('2026-03-10 11:35'), 'endsAt' => new \DateTimeImmutable('2026-03-10 12:30')],
        ];
    }

    private function cover(int $slot, ?string $description = null): GuardiaCover
    {
        return (new GuardiaCover())
            ->setDate(new \DateTimeImmutable('2026-03-10'))
            ->setSlotIndex($slot)
            ->setAbsentTeacher((new User())->setFullName('Natalia Rodríguez')->setEmail('natalia@centro.test'))
            ->setTaskDescription((string) $description);
    }

    public function testTheNextOneIsTheFirstPeriodNotYetOver(): void
    {
        // 10:00 — la de 1ª hora (acaba 09:20) ya pasó; la de 2ª (09:20–10:15) está en curso.
        $view = $this->day->forDay([$this->cover(0), $this->cover(1), $this->cover(2)], $this->slotTimes(), new \DateTimeImmutable('2026-03-10 10:00'));

        self::assertSame(1, $view['next'], 'la que está en curso sigue siendo "la próxima": no ha terminado');
        self::assertTrue($view['items'][0]['done']);
        self::assertFalse($view['items'][1]['done']);
        self::assertFalse($view['items'][2]['done']);
    }

    public function testCountsSeparatePendingFromTheWholeDayAndFlagTheOnesWithTask(): void
    {
        $view = $this->day->forDay([$this->cover(0), $this->cover(1, 'Ejercicios 4 y 5'), $this->cover(2)], $this->slotTimes(), new \DateTimeImmutable('2026-03-10 10:00'));

        self::assertSame(3, $view['counts']['assigned'], 'el día entero, incluidas las ya hechas');
        self::assertSame(2, $view['counts']['pending']);
        self::assertSame(1, $view['counts']['withTask']);
    }

    public function testTheCountdownOnlyExistsForAPeriodStillToStart(): void
    {
        $view = $this->day->forDay([$this->cover(0), $this->cover(2)], $this->slotTimes(), new \DateTimeImmutable('2026-03-10 11:05'));

        // La de 1ª hora ya pasó: no hay cuenta atrás hacia atrás.
        self::assertNull($view['items'][0]['minutesUntil']);
        // 11:05 → 11:35 son 30 minutos.
        self::assertSame(30, $view['items'][1]['minutesUntil']);
    }

    public function testAPeriodInProgressHasNoCountdownButIsStillPending(): void
    {
        $view = $this->day->forDay([$this->cover(1)], $this->slotTimes(), new \DateTimeImmutable('2026-03-10 09:30'));

        self::assertSame(0, $view['next']);
        self::assertFalse($view['items'][0]['done']);
        self::assertNull($view['items'][0]['minutesUntil'], 'ya ha empezado: "en X min" mentiría');
    }

    public function testWithoutAnImportedTimetableNothingCountsAsDone(): void
    {
        // Sin horario no se sabe cuándo acaba ninguna hora: mejor un "pendiente" caducado que decirle a
        // alguien que su guardia ya pasó cuando no se sabe.
        $view = $this->day->forDay([$this->cover(0), $this->cover(1)], [], new \DateTimeImmutable('2026-03-10 23:00'));

        self::assertSame(0, $view['next']);
        self::assertSame(2, $view['counts']['pending']);
        self::assertFalse($view['items'][0]['done']);
        self::assertNull($view['items'][0]['startsAt']);
    }

    public function testADayWithEveryPeriodOverHasNoNextButStillCountsThem(): void
    {
        $view = $this->day->forDay([$this->cover(0), $this->cover(1)], $this->slotTimes(), new \DateTimeImmutable('2026-03-10 20:00'));

        self::assertNull($view['next'], 'no queda ninguna por hacer');
        self::assertSame(2, $view['counts']['assigned'], 'pero el día tuvo dos: Inicio lo necesita para no decir "hoy no tienes guardia"');
        self::assertSame(0, $view['counts']['pending']);
    }

    public function testAnEmptyDayIsAnEmptyView(): void
    {
        $view = $this->day->forDay([], $this->slotTimes(), new \DateTimeImmutable('2026-03-10 10:00'));

        self::assertSame([], $view['items']);
        self::assertNull($view['next']);
        self::assertSame(['assigned' => 0, 'pending' => 0, 'withTask' => 0], $view['counts']);
    }

    /**
     * @return iterable<string, array{string, int, string, bool}>
     */
    public static function slotOverCases(): iterable
    {
        // [día de la guardia, tramo, ahora, ¿ya pasó?] con el 2º tramo de 09:20 a 10:15.
        yield 'antes de empezar' => ['2026-03-10', 1, '2026-03-10 09:00', false];
        yield 'a mitad de la hora sigue sirviendo' => ['2026-03-10', 1, '2026-03-10 09:50', false];
        yield 'justo al acabar todavía no' => ['2026-03-10', 1, '2026-03-10 10:15', false];
        yield 'un minuto después ya pasó' => ['2026-03-10', 1, '2026-03-10 10:16', true];
        yield 'un día anterior ya pasó, sea la hora que sea' => ['2026-03-09', 1, '2026-03-10 08:00', true];
        yield 'un día que viene no ha pasado' => ['2026-03-11', 1, '2026-03-10 23:00', false];
        yield 'un tramo sin hora conocida no se da por pasado' => ['2026-03-10', 7, '2026-03-10 23:00', false];
    }

    #[DataProvider('slotOverCases')]
    public function testWhetherAPeriodIsOver(string $date, int $slot, string $now, bool $over): void
    {
        self::assertSame($over, $this->day->isSlotOver(new \DateTimeImmutable($date), $slot, $this->slotTimes(), new \DateTimeImmutable($now)));
    }

    public function testTheSlotTimesAreReadAsHoursOfTheGivenDayNotOfTheDayTheyCarry(): void
    {
        // Las horas del horario llegan fechadas con el día del PROCESO (aquí el 10). Mirando el 20 a las
        // 11:00, el 2º tramo ya acabó: comparar con la fecha que traen daría otra respuesta.
        self::assertTrue($this->day->isSlotOver(new \DateTimeImmutable('2026-03-20'), 1, $this->slotTimes(), new \DateTimeImmutable('2026-03-20 11:00')));
        self::assertFalse($this->day->isSlotOver(new \DateTimeImmutable('2026-03-20'), 2, $this->slotTimes(), new \DateTimeImmutable('2026-03-20 11:00')));
    }

    public function testAClockInAnotherZoneIsReadInTheCentresZone(): void
    {
        // 09:30 UTC son las 10:30 en Madrid (marzo, UTC+1): el 2º tramo (hasta las 10:15) ya pasó.
        $madrid = new \DateTimeZone('Europe/Madrid');
        $now = new \DateTimeImmutable('2026-03-10 09:30', new \DateTimeZone('UTC'));

        self::assertTrue($this->day->isSlotOver(new \DateTimeImmutable('2026-03-10', $madrid), 1, $this->slotTimes(), $now));
    }
}
