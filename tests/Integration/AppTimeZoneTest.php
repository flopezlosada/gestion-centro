<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Util\AppTime;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The centre's time zone is an invariant of the application, not of the machine it runs on.
 *
 * It gets its own test because it is exactly the kind of thing that is green everywhere and wrong in
 * production: locally DDEV derives the zone from the host (Madrid), CI runs in UTC, and the hosting
 * has whatever its php.ini says. Nothing would fail loudly — Inicio and the Calendario would simply
 * answer a different DAY for a couple of hours every night, and a task due "hoy" would show up
 * tomorrow.
 */
final class AppTimeZoneTest extends KernelTestCase
{
    /**
     * Booting the kernel anchors PHP's default zone to the centre's, whatever the machine had — which
     * is the whole point: the guarantee cannot depend on the deployment remembering to set php.ini.
     */
    public function testBootingTheKernelAnchorsTheDefaultZoneToTheCentres(): void
    {
        $before = date_default_timezone_get();

        try {
            // A zone the centre is deliberately NOT in, so a passing test cannot be an accident of the
            // machine already being right.
            date_default_timezone_set('UTC');

            self::bootKernel();

            self::assertSame(AppTime::ZONE, date_default_timezone_get());
        } finally {
            date_default_timezone_set($before);
        }
    }

    /**
     * The three ways the codebase asks what day it is have to agree, since that disagreement IS the
     * bug: `new \DateTimeImmutable('today')` (42 call sites), `date_default_timezone_get()` (the query
     * string parsers) and {@see AppTime::zone()} (the calendar grid).
     */
    public function testTheThreeSpellingsOfTodayAgree(): void
    {
        self::bootKernel();

        $implicit = new \DateTimeImmutable('today');
        $spelledOut = new \DateTimeImmutable('today', new \DateTimeZone(date_default_timezone_get()));
        $anchored = new \DateTimeImmutable('today', AppTime::zone());

        self::assertSame($implicit->format('Y-m-d H:i'), $spelledOut->format('Y-m-d H:i'));
        self::assertSame($implicit->format('Y-m-d H:i'), $anchored->format('Y-m-d H:i'));
    }
}
