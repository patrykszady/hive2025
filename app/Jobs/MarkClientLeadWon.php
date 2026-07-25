<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * A project was created for a client — any lead that produced that client has
 * converted, so flip it from New to Won.
 *
 * Queued (dispatched from ProjectObserver) because lead→client resolution can
 * fall back to an address LIKE scan, which has no business slowing down the
 * project-create request.
 */
class MarkClientLeadWon implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $clientId,
        public ?int $vendorId = null,
    ) {}

    public function handle(): void
    {
        $client = Client::withoutGlobalScopes()->find($this->clientId);

        if (! $client) {
            return;
        }

        // Only leads still sitting in New convert — a lead someone already
        // marked Lost/Not a Fit stays where the human put it.
        $leads = Lead::withoutGlobalScopes()
            ->whereLatestStatus('New')
            ->when($this->vendorId, fn ($q) => $q->where('belongs_to_vendor_id', $this->vendorId))
            ->with(['user.clients', 'last_status'])
            ->get();

        foreach ($leads as $lead) {
            if ($lead->resolveClient()?->id !== $client->id) {
                continue;
            }

            if ($lead->setStatus('Won')) {
                Log::info('Lead marked Won after project creation', [
                    'lead_id' => $lead->id,
                    'client_id' => $client->id,
                ]);
            }
        }
    }
}
