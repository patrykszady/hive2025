<?php

namespace App\Support\Ewccv;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * EWCCV is down every night from midnight to 6 am Eastern (Patryk,
 * 2026-10-14): nothing is searched, signed in or asked for a sign-in link in
 * that window. Whatever is waiting stays pending, and the extension's first
 * poll after 6 am hands over a session and runs it. services.ewccv.service_hours
 * turns the rule off (the test suite, which runs at any hour).
 */
class ServiceHours
{
    public const TIMEZONE = 'America/New_York';

    public const OPENS_AT_HOUR = 6;

    public static function isClosed(?CarbonInterface $at = null): bool
    {
        if (! config('services.ewccv.service_hours', true)) {
            return false;
        }

        return self::local($at)->hour < self::OPENS_AT_HOUR;
    }

    /** The next 6 am Eastern, in Eastern time. */
    public static function opensAt(?CarbonInterface $at = null): CarbonInterface
    {
        $local = self::local($at);
        $opens = $local->copy()->setTime(self::OPENS_AT_HOUR, 0);

        return $local->lt($opens) ? $opens : $opens->addDay();
    }

    /** "EWCCV is closed until 6:00 am Eastern", for logs, the extension and the console. */
    public static function closedMessage(?CarbonInterface $at = null): string
    {
        return 'EWCCV is closed nightly from midnight to 6:00 am Eastern; it opens again at '.self::opensAt($at)->format('g:i a T').'.';
    }

    protected static function local(?CarbonInterface $at): CarbonInterface
    {
        return Carbon::instance($at ?? now())->setTimezone(self::TIMEZONE);
    }
}
