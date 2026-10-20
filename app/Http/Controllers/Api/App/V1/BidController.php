<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreBidsRequest;
use App\Models\Bid;
use App\Models\Project;
use App\Models\Vendor;
use App\Services\Bids\AppBidDirectory;
use App\Services\Bids\BidWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * View and add bids on a project exactly like
 * App\Livewire\Bids\BidCreate's "project" context — see
 * App\Services\Bids\AppBidDirectory for the read shape and
 * App\Services\Bids\BidWriter for the shared write logic.
 */
class BidController extends Controller
{
    public function index(Request $request, int $project, int $vendor, AppBidDirectory $directory): JsonResponse
    {
        $foundProject = $this->visibleProject($project);
        $foundVendor = Vendor::query()->findOrFail($vendor);

        return response()->json([
            'data' => $directory->listFor($foundProject, $foundVendor, (int) $request->user()->vendor->id),
        ]);
    }

    /**
     * Unlike the web (whose own BidForm::store() authorization call is
     * commented out), this endpoint actually enforces
     * App\Policies\BidPolicy::create — Admin, non-1099 vendor.
     */
    public function store(StoreBidsRequest $request, int $project, int $vendor, BidWriter $writer): JsonResponse
    {
        $foundProject = $this->visibleProject($project);
        $foundVendor = Vendor::query()->findOrFail($vendor);

        $bids = $writer->upsertMany($foundProject, $foundVendor, $request->validated('bids'));

        return response()->json([
            'data' => collect($bids)->map(fn (Bid $bid) => [
                'id' => $bid->id,
                'amount' => (float) $bid->amount,
                'type' => (int) $bid->type,
                'name' => $bid->name,
                'project_id' => $bid->project_id,
                'vendor_id' => $bid->vendor_id,
            ])->values()->all(),
        ], 201);
    }

    /**
     * Remove a bid/change order exactly like
     * App\Livewire\Bids\BidCreate::removeChangeOrder() — see
     * App\Services\Bids\BidWriter::delete() for the shared guard (never
     * the Original Bid, never one an estimate already references).
     * App\Policies\BidPolicy::delete is the same Admin/non-1099 gate as
     * create.
     */
    public function destroy(Request $request, int $project, int $vendor, int $bid, BidWriter $writer): JsonResponse
    {
        $foundProject = $this->visibleProject($project);
        $foundVendor = Vendor::query()->findOrFail($vendor);
        $foundBid = $foundProject->bids()->where('vendor_id', $foundVendor->id)->findOrFail($bid);

        $this->authorize('delete', $foundBid);

        $writer->delete($foundProject, $foundVendor, $foundBid->id, (int) $request->user()->vendor->id);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** A project the person may see: 404 when it doesn't exist, 403 when it isn't theirs. */
    private function visibleProject(int $id): Project
    {
        abort_unless(Project::withoutGlobalScopes()->whereKey($id)->exists(), 404);

        $project = Project::query()->whereKey($id)->first();
        abort_unless($project, 403, 'You do not have access to this project.');

        Gate::authorize('view', $project);

        return $project;
    }
}
