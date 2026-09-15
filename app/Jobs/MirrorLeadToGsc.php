<?php

namespace App\Jobs;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Every lead starts on ss.systems. Website, Yelp and (since 2026-09-15)
 * email leads are born there (on gs.construction) and arrive here already
 * known; the rest — Angi, Houzz, the hive form, a manual entry — are born
 * here, so this pushes them to gs.construction the moment they exist. The
 * 15-minute pull on that side remains the catch-up.
 */
class MirrorLeadToGsc implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    /** Leads that are born on gs.construction: pushing them back would make twins. */
    public const BORN_ON_GSC = ['gs.construction', 'yelp', 'crew-email'];

    public function __construct(public int $leadId) {}

    public static function configured(): bool
    {
        return trim((string) config('services.gsc.url')) !== '' && trim((string) config('services.gsc.token')) !== '';
    }

    /** What the lead's channel is called on the site: crew-email, angi, houzz, website, manual… */
    public static function sourceFor(Lead $lead): string
    {
        if ($lead->external_source) {
            return (string) $lead->external_source;
        }

        return Str::slug((string) ($lead->origin ?: 'hive')) ?: 'hive';
    }

    public function handle(): void
    {
        if (! self::configured()) {
            return;
        }

        $lead = Lead::withoutGlobalScopes()->find($this->leadId);

        if (! $lead || in_array($lead->external_source, self::BORN_ON_GSC, true)) {
            return;
        }

        $data = $lead->lead_data instanceof \ArrayObject ? $lead->lead_data->toArray() : (array) $lead->lead_data;

        $payload = [
            'hive_lead_id' => $lead->id,
            'source' => self::sourceFor($lead),
            // This side's identity for the lead, so the site can recognise
            // a row it already holds for the same thing.
            'external_id' => $lead->external_id,
            'subject' => $data['subject'] ?? null,
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip' => $data['zip'] ?? null,
            'message' => (string) ($data['message'] ?? $lead->notes ?? ''),
            'received_at' => optional($lead->date)->toIso8601String(),
        ];

        try {
            $response = Http::baseUrl(rtrim((string) config('services.gsc.url'), '/'))
                ->withToken((string) config('services.gsc.token'))
                ->acceptJson()
                ->timeout(15)
                ->post('/api/admin/v1/leads', $payload);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('gs.construction unreachable: ' . $e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            Log::warning('Lead mirror to gs.construction refused', [
                'lead_id' => $lead->id,
                'status' => $response->status(),
                'body' => Str::limit((string) $response->body(), 300),
            ]);

            // 4xx is ours to fix, not to retry; 5xx and the rest get another go.
            if ($response->status() >= 400 && $response->status() < 500) {
                return;
            }

            throw new \RuntimeException("gs.construction answered HTTP {$response->status()}");
        }

        Log::info('Lead mirrored to gs.construction', [
            'lead_id' => $lead->id,
            'source' => $payload['source'],
            'submission_id' => $response->json('data.id'),
            'created' => $response->status() === 201,
        ]);
    }
}
