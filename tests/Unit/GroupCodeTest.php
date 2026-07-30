<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\EducationLevel;
use App\Util\GroupCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading the level and the section off a group's short name is a guess, so it is pinned against the
 * REAL group names of the centre's Peñalara export (the 39 groups of course 2025-2026, one case per
 * naming scheme). If a future export renames its groups these tests fail, which is exactly the signal
 * the level picker needs — it would otherwise silently stop suggesting anything.
 */
final class GroupCodeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, EducationLevel, list<string>}>
     */
    public static function realGroupNames(): iterable
    {
        yield 'ESO 1' => ['E1A', EducationLevel::ESO_1, ['A']];
        yield 'ESO 1, last section' => ['E1E', EducationLevel::ESO_1, ['E']];
        yield 'ESO 2' => ['E2C', EducationLevel::ESO_2, ['C']];
        yield 'ESO 3' => ['E3E', EducationLevel::ESO_3, ['E']];
        yield 'ESO 4' => ['E4D', EducationLevel::ESO_4, ['D']];
        yield 'Diversificación 1, with a space' => ['1DIV A', EducationLevel::DIV_1, ['A']];
        yield 'Diversificación 2, no space' => ['2DIVA', EducationLevel::DIV_2, ['A']];
        yield 'Bachillerato 1' => ['B1A', EducationLevel::BACH_1, ['A']];
        yield 'Bachillerato 2' => ['B2B', EducationLevel::BACH_2, ['B']];
        // Grado Básico has a single group per year: its name carries no section letter.
        yield 'Grado Básico 1' => ['1GBASICO', EducationLevel::GB_1, []];
        yield 'Grado Básico 2' => ['2GBASICO', EducationLevel::GB_2, []];
    }

    /**
     * @param list<string> $expectedSections
     */
    #[DataProvider('realGroupNames')]
    public function testReadsEveryRealGroupNamingScheme(string $groupName, EducationLevel $expectedLevel, array $expectedSections): void
    {
        self::assertSame($expectedLevel, GroupCode::level($groupName));
        self::assertSame($expectedSections, GroupCode::sections($groupName));
    }

    public function testIsCaseInsensitive(): void
    {
        self::assertSame(EducationLevel::ESO_4, GroupCode::level('e4d'));
        self::assertSame(['D'], GroupCode::sections('e4d'));
    }

    public function testAMultiGroupActivityYieldsItsLevelAndEverySection(): void
    {
        // A whole-level session in the assembly hall snapshots every group on one cover.
        self::assertSame(EducationLevel::DIV_2, GroupCode::level('2DIVA, 2DIVB, 2DIVC'));
        self::assertSame(['A', 'B', 'C'], GroupCode::sections('2DIVA, 2DIVB, 2DIVC'));
    }

    public function testSkipsUnrecognisableGroupsInsteadOfGivingUp(): void
    {
        self::assertSame(EducationLevel::ESO_3, GroupCode::level('OPTATIVA, E3B'));
        self::assertSame(['B'], GroupCode::sections('OPTATIVA, E3B'));
    }

    public function testYieldsNothingWhenTheNameFollowsNoKnownScheme(): void
    {
        self::assertNull(GroupCode::level('TALLER'));
        self::assertNull(GroupCode::level(''));
        self::assertNull(GroupCode::level(null));
        self::assertSame([], GroupCode::sections('TALLER'));
        self::assertSame([], GroupCode::sections(null));
    }

    public function testAnUnrestrictedTaskFitsAnyClass(): void
    {
        self::assertTrue(GroupCode::sectionsMatch([], ['D']));
        self::assertTrue(GroupCode::sectionsMatch([], []));
    }

    public function testARestrictedTaskOnlyFitsItsSections(): void
    {
        self::assertTrue(GroupCode::sectionsMatch(['A', 'C'], ['C']));
        self::assertFalse(GroupCode::sectionsMatch(['A', 'C'], ['B']));
        // An optional subject carries no letter of its own: only unrestricted tasks apply to it.
        self::assertFalse(GroupCode::sectionsMatch(['A'], []));
    }

    public function testParsesTypedLettersIntoTheCanonicalList(): void
    {
        self::assertSame(['A', 'C'], GroupCode::parseSections('c, a'));
        self::assertSame(['A'], GroupCode::parseSections('A y A'));
        self::assertSame([], GroupCode::parseSections('todos'));
        self::assertSame([], GroupCode::parseSections(null));
    }
}
