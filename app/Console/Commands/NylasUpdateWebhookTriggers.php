<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class NylasUpdateWebhookTriggers extends Command
{
    protected $signature = 'nylas:update-webhook-triggers {--list : List current webhooks only}';

    protected $description = 'Update Nylas webhook triggers to include message.created for reliable reply tracking';

    /**
     * The triggers we need for complete tracking.
     */
    protected array $requiredTriggers = [
        'message.opened',
        'message.link_clicked',
        'message.created',
        'message.updated',
        'message.bounced',
        'thread.replied',
    ];

    public function handle(): int
    {
        $apiKey = config('nylas.api_key');
        $apiUri = config('nylas.api_uri', 'https://api.us.nylas.com');

        if (!$apiKey) {
            $this->error('NYLAS_API_KEY is not configured.');
            return self::FAILURE;
        }

        // List current webhooks
        $response = Http::withToken($apiKey)
            ->get("{$apiUri}/v3/webhooks");

        if (!$response->successful()) {
            $this->error('Failed to fetch webhooks: ' . $response->body());
            return self::FAILURE;
        }

        $webhooks = $response->json('data', []);

        if (empty($webhooks)) {
            $this->warn('No webhooks found. You need to create a webhook in Nylas dashboard first.');
            return self::FAILURE;
        }

        $this->info('Current webhooks:');
        foreach ($webhooks as $webhook) {
            $this->line('');
            $this->line("  ID: {$webhook['id']}");
            $this->line("  URL: {$webhook['webhook_url']}");
            $this->line("  Status: {$webhook['status']}");
            $this->line("  Trigger Types: " . implode(', ', $webhook['trigger_types'] ?? []));
        }

        if ($this->option('list')) {
            return self::SUCCESS;
        }

        // Check if message.created is missing
        $webhook = $webhooks[0]; // Assuming single webhook
        $currentTriggers = $webhook['trigger_types'] ?? [];
        $missingTriggers = array_diff($this->requiredTriggers, $currentTriggers);

        if (empty($missingTriggers)) {
            $this->info('');
            $this->info('✅ All required triggers are already configured.');
            return self::SUCCESS;
        }

        $this->warn('');
        $this->warn('Missing triggers: ' . implode(', ', $missingTriggers));

        if (!$this->confirm('Update webhook to add missing triggers?')) {
            return self::SUCCESS;
        }

        // Update the webhook
        $newTriggers = array_unique(array_merge($currentTriggers, $this->requiredTriggers));

        $updateResponse = Http::withToken($apiKey)
            ->put("{$apiUri}/v3/webhooks/{$webhook['id']}", [
                'triggers' => array_values($newTriggers),
            ]);

        if (!$updateResponse->successful()) {
            $this->error('Failed to update webhook: ' . $updateResponse->body());
            return self::FAILURE;
        }

        $this->info('');
        $this->info('✅ Webhook updated successfully!');
        $this->line('New triggers: ' . implode(', ', $newTriggers));

        return self::SUCCESS;
    }
}
