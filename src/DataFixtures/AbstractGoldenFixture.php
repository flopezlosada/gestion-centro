<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;

/**
 * Base class for backbone fixtures that seed the production baseline.
 *
 * They belong to both {@see FixtureGroups::GOLDEN} and {@see FixtureGroups::DEMO} so that
 * `--group=golden` yields a self-contained baseline and `--group=demo` still gets the backbone its
 * sample data depends on.
 */
abstract class AbstractGoldenFixture extends Fixture implements FixtureGroupInterface
{
    /**
     * @return list<string>
     */
    public static function getGroups(): array
    {
        return [FixtureGroups::GOLDEN, FixtureGroups::DEMO];
    }
}
