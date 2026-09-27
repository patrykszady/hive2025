<?php

namespace App\Console\Commands;

use App\Models\Citation;
use App\Support\Citations\LinkCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The one caller of App\Support\Citations\LinkCheck on this app: for every
 * directory with a listing URL, fetch it like a visitor and record
 * whether it links back to this site. Not scheduled anywhere yet (no
 * cron entry in routes/console.php) — run by hand, or wire it to a
 * weekly schedule entry the same way gsc/dawnsellshomes do, once there is
 * a reason to.
 *
 *   php artisan citations:check-links
 */
class CitationsCheckLinks extends Command
{
    protected $signature = 'citations:check-links';

    protected $description = 'Check every citation with a listing URL for a link back to this site';

    public function handle(): int
    {
        $domain = (string) parse_url((string) config('app.marketing_url', config('app.url')), PHP_URL_HOST);
        $names = array_filter([(string) config('app.name'), 'Hive']);
        $checked = 0;

        foreach (Citation::query()->whereNotNull('listing_url')->get() as $citation) {
            $result = LinkCheck::run((string) $citation->listing_url, $domain, $names);
            $citation->links_to_us = $result['links_to_us'] === null ? null : (bool) $result['links_to_us'];
            $citation->nofollow = $result['nofollow'] === null ? null : (bool) $result['nofollow'];
            $citation->last_checked_at = now();
            $citation->addLog('Link check: '.($result['note'] ?? ('HTTP '.$result['status'])), 'link-check');
            $citation->save();
            $checked++;
            Log::info('citations: link check', ['slug' => $citation->slug] + $result);
        }

        $this->info("Checked {$checked} citation(s) with a listing URL.");

        return self::SUCCESS;
    }
}
