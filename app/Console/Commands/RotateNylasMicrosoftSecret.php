<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Keep the Azure app secret behind the Nylas Microsoft connector alive.
 *
 * The original secret expired at its 2-year maximum on 2026-08-11 and took
 * every mailbox down for 18 hours while five dashboards still said "valid".
 * This command closes that class of outage: monthly, it checks the secret's
 * expiry via Microsoft Graph and — inside the renewal window — mints a fresh
 * 2-year secret (the app owns itself: Application.ReadWrite.OwnedBy), points
 * the Nylas connector at it, rewrites its own .env copy, and prunes expired
 * credentials. No calendar reminders, no humans.
 */
class RotateNylasMicrosoftSecret extends Command
{
    protected $signature = 'nylas:rotate-microsoft-secret
        {--days=60 : Rotate when the newest secret expires within this many days}
        {--dry-run : Report expiry state without changing anything}';

    protected $description = 'Auto-renew the Azure client secret used by the Nylas Microsoft connector before it expires.';

    public function handle(): int
    {
        $tenant = (string) config('services.ms_graph.tenant_id');
        $clientId = (string) config('services.ms_graph.client_id');
        $secret = (string) config('services.ms_graph.client_secret');

        if ($tenant === '' || $clientId === '' || $secret === '') {
            $this->warn('MS_GRAPH_* env not configured — nothing to rotate here.');

            return self::SUCCESS;
        }

        $token = $this->graphToken($tenant, $clientId, $secret);

        if ($token === null) {
            // The likeliest cause is the secret ALREADY having expired —
            // exactly the outage this command exists to prevent. Scream.
            Log::channel('nylas')->error('Secret rotation: cannot authenticate to Graph — the connector secret may already be expired. Rotate manually.');
            $this->error('Graph auth failed — is the secret already expired?');

            return self::FAILURE;
        }

        $app = Http::withToken($token)->timeout(30)
            ->get("https://graph.microsoft.com/v1.0/applications(appId='{$clientId}')", [
                '$select' => 'id,passwordCredentials',
            ]);

        if (! $app->successful()) {
            $this->error('Could not read application: HTTP '.$app->status());

            return self::FAILURE;
        }

        $objectId = (string) $app->json('id');
        $credentials = collect($app->json('passwordCredentials') ?? []);
        $newestEnd = $credentials->pluck('endDateTime')->filter()->map(fn ($d) => \Carbon\Carbon::parse($d))->max();

        if (! $newestEnd) {
            $this->error('No password credentials found on the application.');

            return self::FAILURE;
        }

        $daysLeft = (int) floor(now()->diffInDays($newestEnd, false));
        $this->line("Newest secret expires {$newestEnd->toDateString()} ({$daysLeft} days).");

        if ($daysLeft > (int) $this->option('days')) {
            $this->info('Inside its lifetime — no rotation needed.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('Would rotate now (dry run).');

            return self::SUCCESS;
        }

        // ── mint the replacement ────────────────────────────────────────────
        $minted = Http::withToken($token)->timeout(30)
            ->post("https://graph.microsoft.com/v1.0/applications/{$objectId}/addPassword", [
                'passwordCredential' => [
                    'displayName' => 'nylas-auto-'.now()->format('Y'),
                    'endDateTime' => now()->addYears(2)->toIso8601String(),
                ],
            ]);

        if (! $minted->successful()) {
            Log::channel('nylas')->error('Secret rotation: addPassword failed', ['status' => $minted->status(), 'body' => $minted->body()]);
            $this->error('addPassword failed: HTTP '.$minted->status());

            return self::FAILURE;
        }

        $newSecret = (string) $minted->json('secretText');

        // ── point Nylas at it ───────────────────────────────────────────────
        $patched = Http::withToken(config('nylas.api_key'))->timeout(30)
            ->patch(rtrim(config('nylas.api_uri', 'https://api.us.nylas.com'), '/').'/v3/connectors/microsoft', [
                'settings' => ['client_id' => $clientId, 'client_secret' => $newSecret],
            ]);

        if (! $patched->successful()) {
            Log::channel('nylas')->error('Secret rotation: Nylas connector update failed — new secret minted but NOT active', [
                'status' => $patched->status(),
            ]);
            $this->error('Nylas connector update failed: HTTP '.$patched->status());

            return self::FAILURE;
        }

        // ── remember it ourselves ───────────────────────────────────────────
        $this->rewriteEnvSecret($newSecret);

        // ── prune what's already dead ───────────────────────────────────────
        foreach ($credentials as $credential) {
            if (\Carbon\Carbon::parse($credential['endDateTime'])->isPast()) {
                Http::withToken($token)->timeout(30)
                    ->post("https://graph.microsoft.com/v1.0/applications/{$objectId}/removePassword", [
                        'keyId' => $credential['keyId'],
                    ]);
            }
        }

        Log::channel('nylas')->info('Secret rotation: rotated Microsoft connector secret', [
            'new_expiry' => now()->addYears(2)->toDateString(),
        ]);
        $this->info('Rotated. New secret live in Nylas and .env; expires '.now()->addYears(2)->toDateString().'.');

        return self::SUCCESS;
    }

    protected function graphToken(string $tenant, string $clientId, string $secret): ?string
    {
        $response = Http::asForm()->timeout(30)
            ->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
                'client_id' => $clientId,
                'client_secret' => $secret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]);

        return $response->successful() ? (string) $response->json('access_token') : null;
    }

    /**
     * Swap MS_GRAPH_CLIENT_SECRET in .env atomically, so the next rotation
     * (and any manual Graph use) authenticates with the live secret.
     */
    protected function rewriteEnvSecret(string $newSecret): void
    {
        $path = base_path('.env');
        $contents = file_get_contents($path);

        $updated = preg_replace(
            '/^MS_GRAPH_CLIENT_SECRET=.*$/m',
            'MS_GRAPH_CLIENT_SECRET='.$newSecret,
            $contents,
            1,
            $count,
        );

        if ($count === 0) {
            $updated = rtrim($contents)."\nMS_GRAPH_CLIENT_SECRET={$newSecret}\n";
        }

        $temp = $path.'.rotating';
        file_put_contents($temp, $updated);
        rename($temp, $path);
    }
}
