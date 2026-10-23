<?php

use App\Util\AppTime;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// The Kernel anchors the zone when it boots, but a test can reach the clock before any kernel does:
// ClockSensitiveTrait's #[BeforeClass] calls Clock::get(), whose NativeClock keeps the zone it was built
// in for the whole run, and every mocked time is then read in it. The first test class of the suite
// doing that in CI (UTC) shifted "09:50" two hours for every later test.
date_default_timezone_set(AppTime::ZONE);

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
