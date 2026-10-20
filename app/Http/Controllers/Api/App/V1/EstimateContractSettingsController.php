<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\UpdateEstimateContractSettingsRequest;
use App\Models\Bid;
use App\Models\Estimate;
use App\Services\Estimates\EstimateContractSettingsWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Required contract signer selection, contract template choice, and
 * manually reassigning a section's bid — the rest of
 * App\Livewire\Estimates\EstimateAccept's "Settings" modal beyond the
 * payment schedule (see EstimatePaymentScheduleController for that slice).
 * Both this controller and EstimateAccept::save() write through
 * App\Services\Estimates\EstimateContractSettingsWriter.
 */
class EstimateContractSettingsController extends Controller
{
    /**
     * Everything the app's settings screen needs to prefill: the vendor's
     * contract templates, the vendor users who can be required signers,
     * the project's bids (Original first, like EstimateAccept::mount()),
     * and each section's current bid/total.
     */
    public function show(Request $request, int $estimate, EstimateContractSettingsWriter $writer): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $bids = $writer->bids($found);

        // Same auto-scaffold EstimateAccept::mount() does when opening the
        // modal on a project with no bids yet (only when $found->project
        // can be edited, i.e. always true here since 'update' just passed).
        $bids = $writer->ensureOriginalBid($found, $bids);

        $originalBidId = $bids->first()->id;

        $sections = $found->estimate_sections;

        return response()->json(['data' => [
            'contract_templates' => $writer->contractTemplates($found)->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
            'vendor_users' => $writer->vendorUsers($found)->map(fn ($u) => [
                'id' => $u->id,
                'name' => trim($u->first_name.' '.$u->last_name),
                'role' => $u->getRoleForVendor($found->belongs_to_vendor_id),
            ])->values()->all(),
            'bids' => $bids->map(fn (Bid $bid) => ['id' => $bid->id, 'type' => $bid->type, 'name' => $bid->name, 'amount' => round((float) $bid->amount, 2)])->values()->all(),
            'original_bid_id' => $originalBidId,
            'sections' => $sections->map(fn ($section) => [
                'id' => $section->id,
                'name' => $section->name,
                'total' => round((float) $section->total, 2),
                'bid_id' => $section->bid_id ?: $originalBidId,
            ])->values()->all(),
            'required_vendor_signer_ids' => array_map('intval', (array) ($found->options['required_vendor_signer_ids'] ?? [])),
            'contract_template_ids' => array_map('intval', (array) ($found->options['contract_template_ids'] ?? [])),
        ]]);
    }

    /**
     * Saves signer ids, contract template ids, and the section -> bid
     * assignment — mirrors the rest of EstimateAccept::save() via
     * App\Services\Estimates\EstimateContractSettingsWriter.
     */
    public function update(UpdateEstimateContractSettingsRequest $request, int $estimate, EstimateContractSettingsWriter $writer): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $writer->save(
            $found,
            $request->validated('required_vendor_signer_ids') ?? [],
            $request->validated('contract_template_ids') ?? [],
            $request->validated('section_bids') ?? [],
        );

        return $this->show($request, $estimate, $writer);
    }

    /**
     * A brand-new change-order bid — mirrors EstimateAccept::newEstimateBid()
     * (the "+ Bid" button next to a section's bid select).
     */
    public function addBid(Request $request, int $estimate, EstimateContractSettingsWriter $writer): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $bid = $writer->addBid($found);

        return response()->json(['data' => ['id' => $bid->id, 'type' => $bid->type, 'name' => $bid->name, 'amount' => 0.0]], 201);
    }

    private function visibleEstimate(int $id): Estimate
    {
        abort_unless(Estimate::withoutGlobalScopes()->whereKey($id)->exists(), 404);
        $found = Estimate::query()->whereKey($id)->first();
        abort_unless($found, 403, 'You do not have access to this estimate.');

        return $found;
    }
}
