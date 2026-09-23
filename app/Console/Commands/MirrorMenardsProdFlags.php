<?php

namespace App\Console\Commands;

use App\Http\Controllers\MenardsSyncStatusController;
use App\Services\MenardsRemoteBrowserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * Copy the Menards browser flags out of production's cache into this one,
 * so the sidebar's "Menards — Sign-in" badge and the Menards page show what
 * prod shows. The flags are set by menards:browser, which only runs on the
 * server, and live nowhere but its cache: db:pull-production cannot carry
 * them, and its cache:clear drops any copy, so it calls this at the end.
 * Production is only ever read; this never runs there.
 */
class MirrorMenardsProdFlags extends Command
{
    protected $signature = 'menards:mirror-prod-flags
        {--host=hive-prod : SSH host alias for the production server}
        {--remote-path=hive.contractors : App directory on the server}';

    protected $description = "Copy the Menards browser flags from production's cache so the sidebar and the Menards page match prod";

    /** What the sidebar and the Menards page read. */
    private const KEYS = [
        MenardsRemoteBrowserService::NEEDS_SIGNIN_CACHE_KEY,
        MenardsSyncStatusController::CACHE_KEY,
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('This command never runs in production.');

            return self::FAILURE;
        }

        $pairs = implode(', ', array_map(
            fn (string $key) => sprintf('%s => Cache::get(%s)', var_export($key, true), var_export($key, true)),
            self::KEYS,
        ));
        $remote = 'cd ~/'.escapeshellarg(trim((string) $this->option('remote-path'), '/'))
            .' && php artisan tinker --execute='.escapeshellarg("echo json_encode([{$pairs}]);");

        $result = Process::timeout(60)->run([
            'ssh', '-o', 'ConnectTimeout=10', '-o', 'BatchMode=yes', (string) $this->option('host'), $remote,
        ]);

        $flags = $this->lastJsonObject($result->output());

        if (! $result->successful() || $flags === null) {
            $this->error('Could not read the Menards flags from production: '.trim($result->errorOutput() ?: $result->output()));

            return self::FAILURE;
        }

        foreach (self::KEYS as $key) {
            if (($flags[$key] ?? null) === null) {
                Cache::forget($key);
                $this->line("{$key}: not set on production, cleared here");

                continue;
            }

            Cache::put($key, $flags[$key], now()->addMonth());
            $this->line("{$key}: ".json_encode($flags[$key], JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    /**
     * Tinker may print notices before the JSON, so take the last line that
     * decodes to an object.
     *
     * @return array<string, mixed>|null
     */
    private function lastJsonObject(string $output): ?array
    {
        foreach (array_reverse(preg_split('/\R/', trim($output)) ?: []) as $line) {
            $decoded = json_decode(trim($line), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
