<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AppSetting;
use App\Enum\AbsenceRetention;
use App\Repository\AppSettingRepository;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The plazo the teachers' absences are kept for: whole weeks back from a day, and never longer than the
 * privacy notice says because of a badly stored setting.
 */
final class AbsenceRetentionTest extends TestCase
{
    public function testTheFirstDayKeptIsWholeWeeksBackAtMidnight(): void
    {
        $today = new \DateTimeImmutable('2026-10-22 03:00');

        self::assertSame('2026-10-15 00:00', AbsenceRetention::ONE_WEEK->keptFrom($today)->format('Y-m-d H:i'));
        self::assertSame('2026-09-24 00:00', AbsenceRetention::FOUR_WEEKS->keptFrom($today)->format('Y-m-d H:i'));
        // El cambio de hora del 25 de octubre no mueve el día: se cuentan días, no horas.
        self::assertSame('2026-10-22 00:00', AbsenceRetention::ONE_WEEK->keptFrom(new \DateTimeImmutable('2026-10-29 09:00'))->format('Y-m-d H:i'));
    }

    public function testTheLabelsReadAsTheFormOffersThem(): void
    {
        self::assertSame(['1 semana', '2 semanas', '3 semanas', '4 semanas'], array_map(static fn (AbsenceRetention $r): string => $r->label(), AbsenceRetention::cases()));
    }

    /**
     * @return iterable<string, array{string|null, AbsenceRetention}>
     */
    public static function storedValues(): iterable
    {
        yield 'nunca guardado' => [null, AbsenceRetention::ONE_WEEK];
        yield 'dos semanas' => ['2', AbsenceRetention::TWO_WEEKS];
        yield 'cuatro semanas' => ['4', AbsenceRetention::FOUR_WEEKS];
        yield 'cero' => ['0', AbsenceRetention::ONE_WEEK];
        yield 'más de las que hay' => ['5', AbsenceRetention::ONE_WEEK];
        yield 'no es un número' => ['x', AbsenceRetention::ONE_WEEK];
        yield 'vacío' => ['', AbsenceRetention::ONE_WEEK];
        yield 'negativo' => ['-2', AbsenceRetention::ONE_WEEK];
    }

    #[DataProvider('storedValues')]
    public function testABadlyStoredPlazoReadsAsOneWeek(?string $stored, AbsenceRetention $expected): void
    {
        $rows = null === $stored ? [] : [AppSettings::ABSENCE_RETENTION_WEEKS => new AppSetting(AppSettings::ABSENCE_RETENTION_WEEKS, $stored)];
        $repository = $this->createStub(AppSettingRepository::class);
        $repository->method('findAllIndexed')->willReturn($rows);
        $settings = new AppSettings($repository, $this->createStub(EntityManagerInterface::class), 'IES de prueba', 'fotocopias@centro.test', '', '');

        self::assertSame($expected, $settings->getAbsenceRetention());
    }
}
