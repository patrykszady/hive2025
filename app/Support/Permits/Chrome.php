<?php

namespace App\Support\Permits;

use Spatie\Browsershot\Browsershot;

/**
 * The one way this app starts headless Chrome through Browsershot: the
 * sandbox flags php-fpm needs on hive-prod (FPM is systemd-sandboxed) and
 * the CHROME_PATH override, applied to any Browsershot instance.
 */
class Chrome
{
    public static function configure(Browsershot $browsershot): Browsershot
    {
        $browsershot
            ->newHeadless()
            ->addChromiumArguments([
                'no-sandbox',
                'disable-setuid-sandbox',
                'disable-dev-shm-usage',
                'disable-gpu',
                'single-process',
            ]);

        if ($chromePath = config('services.chrome.path')) {
            $browsershot->setChromePath($chromePath);
        }

        return $browsershot;
    }
}
