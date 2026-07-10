<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Support\ChangeNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the change normaliser: scalars, dates, enums, arrays and secret redaction,
 * without a kernel or a database.
 */
final class ChangeNormalizerTest extends TestCase
{
    public function testNormalizeKeepsScalarsAndNull(): void
    {
        self::assertNull(ChangeNormalizer::normalize(null));
        self::assertSame(42, ChangeNormalizer::normalize(42));
        self::assertSame('hola', ChangeNormalizer::normalize('hola'));
        self::assertTrue(ChangeNormalizer::normalize(true));
    }

    public function testNormalizeFormatsDatesAsAtom(): void
    {
        $date = new \DateTimeImmutable('2026-09-01T08:30:00+00:00');

        self::assertSame('2026-09-01T08:30:00+00:00', ChangeNormalizer::normalize($date));
    }

    public function testNormalizeReducesBackedEnumToItsValue(): void
    {
        self::assertSame('task', ChangeNormalizer::normalize(Area::TASK));
        self::assertSame('write', ChangeNormalizer::normalize(PermissionLevel::WRITE));
    }

    public function testNormalizeRecursesIntoArrays(): void
    {
        self::assertSame(['task' => 'write'], ChangeNormalizer::normalize(['task' => PermissionLevel::WRITE]));
    }

    public function testDiffReturnsNullForEmptyChangeSet(): void
    {
        self::assertNull(ChangeNormalizer::diff([]));
    }

    public function testDiffProducesOldNewPairs(): void
    {
        $diff = ChangeNormalizer::diff(['name' => ['Antes', 'Después']]);

        self::assertSame(['name' => ['old' => 'Antes', 'new' => 'Después']], $diff);
    }

    public function testDiffRedactsSensitiveFields(): void
    {
        $diff = ChangeNormalizer::diff(['token' => ['old-secret', 'new-secret']]);

        self::assertSame(['token' => ['old' => '***', 'new' => '***']], $diff);
    }
}
