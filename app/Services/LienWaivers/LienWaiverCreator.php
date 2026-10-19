<?php

namespace App\Services\LienWaivers;

use App\Enums\LienWaiverStatus;
use App\Enums\LienWaiverType;
use App\Models\LienWaiver;
use App\Models\Project;
use App\Models\Vendor;

/**
 * A single, ad-hoc lien waiver — the GC's own waiver to the project's
 * client, or a sub/supplier's waiver to the GC — exactly as
 * App\Livewire\LienWaivers\Index::createWaiver() creates it (the GCSS
 * draw/sworn-statement flow is its own, much larger thing and stays there).
 * Factored out so the Hive Contractors app's "Request a waiver" action
 * writes the exact same record.
 */
class LienWaiverCreator
{
    /**
     * @param  array{name?: string, address?: string, city_state_zip?: string}  $payer  Required (and used) only for a GC→owner waiver; a sub waiver's payer is the GC itself.
     */
    public function create(
        Project $project,
        Vendor $contractor,
        LienWaiverType $type,
        int $claimantVendorId,
        ?string $amount,
        string $throughDate,
        array $payer,
        ?string $note,
        int $createdByUserId,
    ): LienWaiver {
        $isSub = $claimantVendorId !== (int) $contractor->id;
        $isPaidInFull = $type === LienWaiverType::UnconditionalFinal;

        $notesPayload = ['manual' => true];

        if (! $isSub) {
            $notesPayload['payer'] = [
                'name' => trim((string) ($payer['name'] ?? '')),
                'address' => trim((string) ($payer['address'] ?? '')),
                'city_state_zip' => trim((string) ($payer['city_state_zip'] ?? '')),
            ];
        }

        if (($note ?? '') !== '') {
            $notesPayload['note'] = trim((string) $note);
        }

        return LienWaiver::create([
            // Payer is always the contractor of record; claimant is the GC
            // (own waiver) or the selected sub/supplier.
            'belongs_to_vendor_id' => $contractor->id,
            'vendor_id' => $claimantVendorId,
            'project_id' => $project->id,
            'check_id' => null,
            'payment_id' => null,
            'type' => $type,
            'status' => LienWaiverStatus::Draft,
            'amount' => $isPaidInFull ? 0 : $amount,
            'exceptions_amount' => 0,
            'through_date' => $throughDate,
            'jurisdiction' => $this->jurisdictionFor($project),
            'notes' => json_encode($notesPayload),
            'created_by_user_id' => $createdByUserId,
        ]);
    }

    /** Map the project's state to a lien-waiver jurisdiction code. */
    public function jurisdictionFor(Project $project): string
    {
        $state = strtoupper((string) $project->state);

        return in_array($state, ['CA', 'AZ', 'FL', 'GA', 'IL', 'MS', 'NV', 'TX', 'UT', 'WY'], true)
            ? 'US-'.$state
            : 'US-GENERIC';
    }
}
