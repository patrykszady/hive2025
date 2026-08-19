<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Purge Cloudflare's edge cache for hive.contractors.
 *
 * Deliberately leaner than gs.construction's command of the same name: that
 * one enumerates SEO surfaces (/areas-served, /services, …) because it fronts
 * a marketing site. This app is almost entirely authenticated, and Vite
 * assets are content-hashed, so there is no routine cache problem to solve.
 * What there IS: the occasional file that must stop being served NOW —
 * public/perf.html and public/EWCCV/*.png were both deleted from the server
 * on 2026-08-19 yet kept returning 200 from Cloudflare for hours.
 *
 * Credentials (config/services.php → cloudflare):
 *   CLOUDFLARE_API_TOKEN   the token needs an "All Domains" (zone) policy;
 *                          an account-scoped policy CANNOT purge, no matter
 *                          how many permissions it carries.
 *   CLOUDFLARE_ZONE_ID     zone overview → right column.
 *
 * Usage:
 *   php artisan cloudflare:purge --all
 *   php artisan cloudflare:purge --urls=https://hive.contractors/perf.html
 *   php artisan cloudflare:purge --all --dry-run
 */
class CloudflarePurgeCache extends Command
{
    protected $signature = 'cloudflare:purge
        {--all : Purge the entire zone}
        {--urls= : Comma-separated exact URLs to purge (max 30 per Cloudflare request)}
        {--dry-run : Show what would be purged without calling Cloudflare}';

    protected $description = 'Purge the Cloudflare edge cache for this site.';

    public function handle(): int
    {
        $token = config('services.cloudflare.token');
        $zone = config('services.cloudflare.zone_id');

        if (! $token || ! $zone) {
            $this->error('CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID must both be set.');

            return self::FAILURE;
        }

        $urls = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('urls')))));

        if (! $this->option('all') && $urls === []) {
            $this->error('Nothing to do: pass --all or --urls=…');

            return self::FAILURE;
        }

        // Cloudflare caps the files list at 30 per request.
        $payload = $this->option('all') ? ['purge_everything' => true] : ['files' => array_slice($urls, 0, 30)];

        if ($urls !== [] && count($urls) > 30) {
            $this->warn('  Only the first 30 URLs are purged per request; '.(count($urls) - 30).' ignored.');
        }

        if ($this->option('dry-run')) {
            $this->line('  DRY RUN — would send: '.json_encode($payload));

            return self::SUCCESS;
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->post("https://api.cloudflare.com/client/v4/zones/{$zone}/purge_cache", $payload);

        if ($response->successful() && $response->json('success')) {
            $this->info('  Cloudflare cache purged.');

            return self::SUCCESS;
        }

        // Surface Cloudflare's own message — "Authentication error" here almost
        // always means the token is account-scoped rather than zone-scoped.
        foreach ((array) $response->json('errors') as $error) {
            $this->error('  Cloudflare: '.($error['code'] ?? '?').' '.($error['message'] ?? ''));
        }

        return self::FAILURE;
    }
}
