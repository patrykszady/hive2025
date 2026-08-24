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

        if (! $this->option('register')) {
            $this->line('Nothing to do. Use --register, --list, or --delete=<id>.');

            return self::SUCCESS;
        }

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

        $this->info('Webhook registered: ' . (string) $response->json('data.id'));
        $this->newLine();
        $this->warn('Set this in the environment NOW — Nylas will not show it again:');
        $this->line("NYLAS_WEBHOOK_SECRET={$secret}");

        return self::SUCCESS;
    }
}
