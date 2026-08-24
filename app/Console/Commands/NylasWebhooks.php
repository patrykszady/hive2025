<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Manage the Nylas v3 webhook subscription for message.created.
 *
 * Registration must run AFTER the endpoint is deployed: Nylas GETs the
 * callback with a challenge during creation and refuses the webhook unless
 * it echoes back. The webhook_secret in the response is shown ONCE — put it
 * in the environment as NYLAS_WEBHOOK_SECRET immediately.
 */
class NylasWebhooks extends Command
{
    protected $signature = 'nylas:webhooks
        {--ensure : Idempotent: register if missing, heal a lost secret, else no-op. Safe on a schedule.}
        {--register : Create the message.created webhook pointing at this app}
        {--list : Show existing webhooks}
        {--delete= : Delete a webhook by id}';

    protected $description = 'Register, list, or delete the Nylas webhook subscription';

    public function handle(): int
    {
        $base = rtrim((string) config('nylas.api_uri', 'https://api.us.nylas.com'), '/');
        $key = (string) config('nylas.api_key');

        if ($key === '') {
            $this->error('NYLAS_API_KEY is not configured.');

            return self::FAILURE;
        }

        if ($this->option('list')) {
            $response = Http::withToken($key)->timeout(30)->get("$base/v3/webhooks");

            foreach ((array) $response->json('data', []) as $hook) {
                $this->line(sprintf(
                    '%s  %s  [%s]  %s',
                    $hook['id'] ?? '?',
                    $hook['status'] ?? '?',
                    implode(',', (array) ($hook['trigger_types'] ?? [])),
                    $hook['webhook_url'] ?? '?',
                ));
            }

            return self::SUCCESS;
        }

        if ($id = $this->option('delete')) {
            $response = Http::withToken($key)->timeout(30)->delete("$base/v3/webhooks/{$id}");
            $this->line($response->successful() ? "Deleted {$id}." : "Delete failed: HTTP {$response->status()}");

            return $response->successful() ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('ensure')) {
            return $this->ensure($base, $key);
        }

        if (! $this->option('register')) {
            $this->line('Nothing to do. Use --ensure, --register, --list, or --delete=<id>.');

            return self::SUCCESS;
        }

        return $this->register($base, $key);
    }

    /**
     * Idempotent convergence, built to run unattended (hourly schedule):
     *
     *   webhook missing            -> create it, cache the secret
     *   webhook there, secret lost -> rotate the secret, cache the new one
     *   webhook there, secret held -> nothing
     *
     * The secret lives in the cache (config('nylas.webhook_secret') overrides
     * when set), so no deploy step has to copy anything anywhere. Nylas shows
     * a secret only at creation and rotation — which is exactly when this
     * stores it.
     */
    protected function ensure(string $base, string $key): int
    {
        $url = route('webhooks.nylas');

        $response = Http::withToken($key)->timeout(30)->get("$base/v3/webhooks");

        if (! $response->successful()) {
            $this->error("Could not list webhooks: HTTP {$response->status()}");

            return self::FAILURE;
        }

        $existing = collect((array) $response->json('data', []))->first(
            fn ($hook) => (($hook['webhook_url'] ?? null) === $url)
                && in_array('message.created', (array) ($hook['trigger_types'] ?? []), true)
        );

        if (! $existing) {
            return $this->register($base, $key);
        }

        $secretKnown = (string) config('nylas.webhook_secret') !== ''
            || (string) cache()->get('nylas:webhook-secret', '') !== '';

        if ($secretKnown) {
            $this->line('Webhook present, secret held — nothing to do.');

            return self::SUCCESS;
        }

        // Registered but the signing key is gone (cache flush, redeploy from
        // scratch). Rotate: the only other moment Nylas reveals a secret.
        $rotate = Http::withToken($key)->timeout(30)
            ->post("$base/v3/webhooks/rotate-secret/" . (string) $existing['id']);

        if (! $rotate->successful()) {
            $this->error("Secret rotation failed: HTTP {$rotate->status()}");

            return self::FAILURE;
        }

        cache()->forever('nylas:webhook-secret', (string) $rotate->json('data.webhook_secret'));
        $this->info('Webhook secret rotated and cached.');

        return self::SUCCESS;
    }

    protected function register(string $base, string $key): int
    {
        $url = route('webhooks.nylas');

        $response = Http::withToken($key)->timeout(60)->post("$base/v3/webhooks", [
            'trigger_types' => ['message.created'],
            'webhook_url' => $url,
            'description' => 'Hive reply capture (personal inboxes)',
        ]);

        if (! $response->successful()) {
            $this->error("Registration failed: HTTP {$response->status()}");
            $this->line((string) $response->body());

            return self::FAILURE;
        }

        $secret = (string) $response->json('data.webhook_secret');
        cache()->forever('nylas:webhook-secret', $secret);

        $this->info('Webhook registered and secret cached: ' . (string) $response->json('data.id'));
        $this->line('Optional: set NYLAS_WEBHOOK_SECRET in the env to pin it; the cache already works.');

        return self::SUCCESS;
    }
}
