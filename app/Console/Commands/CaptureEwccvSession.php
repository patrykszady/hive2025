<?php

namespace App\Console\Commands;

use App\Http\Controllers\EwccvSessionController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Capture an EWCCV search session by letting a person sign in and search once.
 *
 * ewccv.com's search API is gated by reCAPTCHA v3 Enterprise — a score, not a
 * puzzle. Nothing an automated browser produces is accepted (measured: tokens
 * from anti-captcha and 2captcha at every score target, and tokens minted by
 * EWCCV's own page in a real headed Chrome, are all refused; the login JWT
 * alone returns 401 on search). A person driving a browser passes it without
 * trying.
 *
 * So this borrows gsc's Yelp shape: do the credential step ONCE in a headed
 * browser against the persistent profile, then let unattended runs reuse what
 * it produced. No extension to install or keep running — the window opens,
 * you search once, it closes.
 *
 * One capture goes a long way: EWCCV re-verifies only every 25 searches, and a
 * full backfill of every vendor is well under that.
 */
class CaptureEwccvSession extends Command
{
    protected $signature = 'ewccv:capture-session
        {--timeout=600 : Seconds to wait for the sign-in and search}
        {--output-dir= : Chrome profile/output dir (default storage/files/_temp_ewccv)}';

    protected $description = 'Open EWCCV in a visible browser, capture the search session after one manual search';

    public function handle(): int
    {
        $outputDir = $this->option('output-dir') ?: storage_path('files/_temp_ewccv');
        @mkdir($outputDir, 0755, true);

        $display = $this->resolveDisplay();

        if (! $display) {
            $this->error('No graphical display available, and Xvfb is not installed.');
            $this->line('This command needs to show you a browser window. Either run it on a machine with a');
            $this->line('desktop, or install Xvfb (apt install xvfb) and connect a viewer.');

            return self::FAILURE;
        }

        $configFile = tempnam(sys_get_temp_dir(), 'ewccv_capture_');
        file_put_contents($configFile, json_encode([
            'outputDir' => $outputDir,
            'timeoutMs' => ((int) $this->option('timeout')) * 1000,
        ]));

        $this->line(str_repeat('═', 62));
        $this->info('Capturing an EWCCV search session');
        $this->line(str_repeat('═', 62));
        $this->line("  Display: {$display}");
        $this->line('  A browser window will open. Sign in, then run ONE search.');
        $this->newLine();

        $script = base_path('scripts/ewccv-session-capture.cjs');
        $node = trim((string) shell_exec('which node')) ?: 'node';

        // Never through the proxy: EWCCV silently drops sign-in emails for
        // requests coming from those IPs (measured — direct delivers in ~20s).
        $result = Process::timeout(((int) $this->option('timeout')) + 60)
            ->env([
                'DISPLAY' => $display,
                'EWCCV_PROXY_HOST' => '',
                'EWCCV_PROXY_PORT' => '',
                'EWCCV_PROXY_USER' => '',
                'EWCCV_PROXY_PASS' => '',
            ])
            ->run("{$node} {$script} {$configFile}", function (string $type, string $output): void {
                if ($type === 'err') {
                    $this->output->write($output);
                }
            });

        @unlink($configFile);

        $payload = json_decode(trim($result->output()), true);

        if (! is_array($payload) || empty($payload['ok']) || empty($payload['accessToken'])) {
            $this->error('  No session captured'.(! empty($payload['error']) ? ": {$payload['error']}" : '.'));

            return self::FAILURE;
        }

        Cache::put(EwccvSessionController::CACHE_KEY, [
            'access_token' => $payload['accessToken'],
            'jwt' => $payload['jwt'] ?? null,
            'state_token' => $payload['stateToken'] ?? null,
            'received_at' => now()->toIso8601String(),
        ], now()->addHours(12));

        Log::info('EWCCV session captured interactively', [
            'access_token_len' => strlen((string) $payload['accessToken']),
        ]);

        $this->newLine();
        $this->info('  Session captured — Hive can search EWCCV now.');
        $this->line('  Run the backfill within the next 45 minutes:');
        $this->line('    php artisan ewccv:scrape-tracking --belongs-to-vendor-id=1');

        return self::SUCCESS;
    }

    /**
     * A display to show the browser on: whatever is already there (a desktop,
     * or WSLg), otherwise a headless X server we start ourselves.
     *
     * Uses :98 like gsc's Yelp login does, so it never collides with a noVNC
     * viewer on :99.
     */
    private function resolveDisplay(): ?string
    {
        if ($existing = getenv('DISPLAY')) {
            return $existing;
        }

        $display = ':98';

        if (file_exists("/tmp/.X11-unix/X".ltrim($display, ':'))) {
            return $display; // already up from a previous run
        }

        if (! trim((string) shell_exec('which Xvfb'))) {
            return null;
        }

        $this->line("  Starting Xvfb on {$display}…");
        Process::run("Xvfb {$display} -screen 0 1400x950x24 >/dev/null 2>&1 & disown");

        for ($i = 0; $i < 20; $i++) {
            usleep(250000);
            if (file_exists('/tmp/.X11-unix/X'.ltrim($display, ':'))) {
                return $display;
            }
        }

        return null;
    }
}
