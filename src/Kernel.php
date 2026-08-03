<?php

namespace App;

use App\Util\AppTime;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Anchors PHP's default time zone to the centre's ({@see AppTime}) before anything can ask what
     * day it is.
     *
     * It goes in the constructor and not in a service, a listener or php.ini because those are all
     * later or optional: the constructor is the single point every entry way goes through — the web
     * front controller, bin/console (so the cron reminders agree with the screens) and
     * KernelTestCase (so CI, which runs in UTC, tests the same day the centre will see). Doctrine
     * hydrates `datetime_immutable` in this very zone, so setting it here is also what keeps a stored
     * clock time and a computed one comparable.
     *
     * @param string $environment the Symfony environment
     * @param bool   $debug       whether debug mode is on
     */
    public function __construct(string $environment, bool $debug)
    {
        date_default_timezone_set(AppTime::ZONE);

        parent::__construct($environment, $debug);
    }
}
