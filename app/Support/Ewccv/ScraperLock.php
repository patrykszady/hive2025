<?php

namespace App\Support\Ewccv;

use Illuminate\Support\Facades\Cache;

/**
 * The one-at-a-time lock every EWCCV scraper run holds (ewccv:scrape-tracking).
 * Two runs would share the Chrome profile and the session; on 2026-10-12 an
 * overlapping pair re-posted a subscription and stamped a fresh policy as
 * failed. The bridge asks here whether a run is on before minting another
 * credential, and the hand-off job before starting another run.
 */
class ScraperLock
{
    public const NAME = 'ewccv:scraper';

    /** Is a scraper run in progress right now? (A one-second probe, released at once.) */
    public static function isHeld(): bool
    {
        $probe = Cache::lock(self::NAME, 1);
        if ($probe->get()) {
            $probe->release();

            return false;
        }

        return true;
    }
}
