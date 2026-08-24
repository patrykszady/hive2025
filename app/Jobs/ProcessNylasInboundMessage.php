<?php

namespace App\Jobs;

use App\Services\CrewLeadEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One Nylas `message.created` doorbell → the same reply pipeline the
 * five-minute sweep runs, minutes earlier.
 *
 * Fetches the message fresh from the API (with RFC headers — the webhook
 * payload has no References/In-Reply-To) and hands it to the shared
 * processor, whose writes all dedupe. If the sweep gets there first, or this
 * runs after it, nothing double-files.
 */
class ProcessNylasInboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        public string $grantId,
        public string $messageId,
    ) {
    }

    public function handle(CrewLeadEmailService $service): void
    {
        $base = rtrim(config('nylas.api_uri', 'https://api.us.nylas.com'), '/');

        $response = Http::withToken(config('nylas.api_key'))
            ->timeout(30)
            ->retry(2, 2000, throw: false)
            ->get("$base/v3/grants/{$this->grantId}/messages/{$this->messageId}", [
                'fields' => 'include_headers',
            ]);

        // Deleted before we got to it (spam purge, user cleanup) — nothing to
        // do, and retrying cannot bring it back.
        if ($response->status() === 404) {
            return;
        }

        if (! $response->successful()) {
            Log::channel('nylas')->warning('Nylas inbound job: message unreadable', [
                'grant_id' => $this->grantId,
                'message_id' => $this->messageId,
                'status' => $response->status(),
            ]);

            $response->throw(); // let the queue retry with backoff
        }

        $message = (array) $response->json('data');

        $outcome = $service->processPersonalInboxMessage(
            $message,
            $this->grantId,
            $service->grantMailboxEmail($this->grantId),
        );

        Log::channel('nylas')->info('Nylas inbound job: processed', [
            'grant_id' => $this->grantId,
            'message_id' => $this->messageId,
            'outcome' => $outcome,
        ]);
    }
}
