<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\LeadAddressCompleter;
use App\Services\LeadContactProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fill in a lead's city/state/ZIP (or record the candidate addresses) away
 * from the request that opened it.
 *
 * Geocoding is a network call to a third party. Doing it while someone clicks
 * a lead made the modal wait on Geoapify — usually fast, occasionally many
 * seconds, which reads as "clicking the lead did nothing".
 */
class CompleteLeadAddress implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(public int $leadId) {}

    /** One in flight per lead is enough. */
    public function uniqueId(): string
    {
        return (string) $this->leadId;
    }

    public function handle(LeadAddressCompleter $completer, LeadContactProvisioner $provisioner): void
    {
        $lead = Lead::withoutGlobalScopes()->find($this->leadId);

        if (! $lead) {
            return;
        }

        $data = (array) $lead->lead_data;
        $completed = $completer->complete($data);

        // Exactly one match near the office is an answer, not a question.
        $candidates = $completed['address_candidates'] ?? [];
        if (is_array($candidates) && count($candidates) === 1) {
            $only = (array) $candidates[0];
            $completed['address'] = $only['address'];
            $completed['city'] = $only['city'];
            $completed['state'] = $only['state'];
            $completed['zip'] = $only['zip_code'];
            unset($completed['address_candidates']);
        }

        if ($completed === $data) {
            return;
        }

        $lead->lead_data = $completed;
        $lead->saveQuietly();

        // A complete address may be the last thing a client record was waiting
        // on — provisioning only ever fills blanks, so this is safe to retry.
        try {
            $provisioner->provision($lead->fresh());
        } catch (\Throwable $e) {
            Log::warning('Lead provisioning after address completion failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
