<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\EducationLevel;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue of levels the centre actually teaches, as declared by its Peñalara export: reading the
 * level off a group name is {@see \App\Util\GroupCode}'s job, tested apart.
 */
final class EducationLevelTest extends TestCase
{
    public function testDisplayOrderCoversEveryLevelExactlyOnce(): void
    {
        $ordered = EducationLevel::inDisplayOrder();
        $values = array_map(static fn (EducationLevel $l): string => $l->value, $ordered);

        self::assertCount(\count(EducationLevel::cases()), $ordered);
        self::assertSame($values, array_values(array_unique($values)), 'ningún nivel repetido en el orden de presentación');
        self::assertSame(EducationLevel::ESO_1, $ordered[0]);
    }

    public function testEveryLevelReadsAsTheCentreSaysIt(): void
    {
        self::assertSame('4º de ESO', EducationLevel::ESO_4->label());
        self::assertSame('2º de Diversificación', EducationLevel::DIV_2->label());
        // Bachillerato is not split by modality: the groups are mixed, so a stand-in task is the same.
        self::assertSame('1º de Bachillerato', EducationLevel::BACH_1->label());
        self::assertSame('1º de Grado Básico', EducationLevel::GB_1->label());
    }
}
