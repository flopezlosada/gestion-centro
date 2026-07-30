<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\GuardiaCover;
use App\Entity\User;
use App\Guardia\TeacherGuardiaDay;
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
}
