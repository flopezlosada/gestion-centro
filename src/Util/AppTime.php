<?php

declare(strict_types=1);

namespace App\Util;

/**
 * The one time zone the application lives in, and the one place it is written down.
 *
 * The centre is in peninsular Spain, so "hoy" is Madrid's day and "las 8:15" is Madrid's clock. That
 * used to be said in three incompatible ways at once: `new \DateTimeImmutable('today')` (whatever zone
 * the machine happened to have), `date_default_timezone_get()` (the same, spelled out) and a literal
 * 'Europe/Madrid' hard-coded in a handful of places. On a server in UTC the first two answer a
 * different DAY than the third for two hours every night — the home said one thing and the calendar
 * another, about the same task.
 *
 * The fix is NOT to spread 'Europe/Madrid' further: Doctrine reads and writes `datetime_immutable`
 * in PHP's default zone WITHOUT converting, so a value anchored in Madrid compared against a column
 * hydrated in UTC is shifted by the offset — that is the bug
 * {@see \App\Service\EventReminderNotifier} refuses on purpose. The fix is to make PHP's default zone
 * BE the centre's, once, at boot ({@see \App\Kernel}); then all three spellings agree and the
 * question stops having two answers.
 *
 * So this class does not offer a "now": with the default zone anchored, plain
 * `new \DateTimeImmutable('today')` is already correct everywhere. What it offers is the zone itself,
 * for the places that genuinely need it as a value (parsing a "YYYY-MM-DD" from the query string into
 * the right midnight, building a calendar grid).
 */
final class AppTime
{
    /**
     * The centre's time zone. Deliberately a constant and not configuration: an IES in La Cabrera is
     * not going to move to another zone, and an environment variable here would only add a way for
     * production to end up in a different day than the tests.
     */
    public const string ZONE = 'Europe/Madrid';

    /**
     * The application time zone as a value, for the callers that need to hand one over (date parsing,
     * calendar grids).
     *
     * @return \DateTimeZone the centre's time zone
     */
    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::ZONE);
    }
}
