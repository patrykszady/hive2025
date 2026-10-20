<?php

namespace App\Services\VendorDocs;

use App\Jobs\SendVendorDocRequestEmail;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Support\Collection;

/**
 * "Request Documents" — the shared write both
 * App\Livewire\VendorDocs\VendorDocCreate::requestDocument() (the web's
 * vendor-docs card button) and the app's VendorDocController::request()
 * call, so an admin asking a vendor to send an updated certificate/license
 * queues the exact same emails from either surface.
 */
class VendorDocRequester
{
    /**
     * Emails requests for every one of $vendor's currently-expired documents
     * (the latest of each type only), routed exactly as the web always has:
     *
     *   1. State license docs (anything that isn't an insurance policy type)
     *      go to the vendor's own business email.
     *   2. Workers comp goes to its agent when one is on file (falling back
     *      to the most recent workers doc that has an agent), else the
     *      vendor's business email.
     *   3. Everything else is grouped by agent (vendor's business email when
     *      a doc has none).
     *
     * @return array{sent: bool, count: int}
     */
    public function requestExpired(Vendor $vendor, Vendor $requestingVendor): array
    {
        $docTypes = VendorDoc::withoutGlobalScopes()
            ->where('vendor_id', $vendor->id)
            ->orderBy('expiration_date', 'DESC')
            ->with('agent')
            ->get()
            ->groupBy('type');

        /** @var Collection<int, VendorDoc> $latestDocs */
        $latestDocs = collect();
        foreach ($docTypes as $typeCertificates) {
            if ($typeCertificates->first()->expiration_date <= today()) {
                $latestDocs->push($typeCertificates->first());
            }
        }

        if ($latestDocs->isEmpty()) {
            return ['sent' => false, 'count' => 0];
        }

        // 1) State license docs (anything that's not an insurance policy type).
        $licenseDocs = $latestDocs->filter(
            fn (VendorDoc $doc) => ! in_array(strtolower((string) $doc->getRawOriginal('type')), ['general', 'professional', 'workers'], true)
        );

        if ($licenseDocs->isNotEmpty()) {
            SendVendorDocRequestEmail::dispatch($licenseDocs, $vendor, $requestingVendor, $vendor->business_email);
        }

        // 2) Workers docs go to the agent when available; fall back to the
        //    most recent workers doc that still has one.
        $workersDocs = $latestDocs->filter(
            fn (VendorDoc $doc) => strtolower((string) $doc->getRawOriginal('type')) === 'workers'
        );

        if ($workersDocs->isNotEmpty()) {
            $workersAgentEmail = $workersDocs->first()->agent?->email;

            if (empty($workersAgentEmail)) {
                $workersAgentEmail = VendorDoc::withoutGlobalScopes()
                    ->where('vendor_id', $vendor->id)
                    ->where('type', 'workers')
                    ->whereNotNull('agent_id')
                    ->with('agent')
                    ->orderByDesc('expiration_date')
                    ->first()?->agent?->email;
            }

            SendVendorDocRequestEmail::dispatch($workersDocs, $vendor, $requestingVendor, $workersAgentEmail ?: $vendor->business_email);
        }

        // 3) Remaining expired docs route by agent grouping.
        $remainingDocs = $latestDocs->reject(
            fn (VendorDoc $doc) => ! in_array(strtolower((string) $doc->getRawOriginal('type')), ['general', 'professional', 'workers'], true)
                || strtolower((string) $doc->getRawOriginal('type')) === 'workers'
        );

        foreach ($remainingDocs->groupBy('agent_id') as $agentId => $agentExpiredDocs) {
            $agent = Agent::find($agentId);
            $agentEmail = $agent?->email ?: $vendor->business_email;

            SendVendorDocRequestEmail::dispatch($agentExpiredDocs, $vendor, $requestingVendor, $agentEmail);
        }

        return ['sent' => true, 'count' => $latestDocs->count()];
    }
}
