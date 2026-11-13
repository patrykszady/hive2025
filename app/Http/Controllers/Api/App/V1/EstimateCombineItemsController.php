<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\CombineEstimateItemsRequest;
use App\Models\Estimate;
use App\Services\Estimates\EstimateLineItemCombiner;
use Illuminate\Http\JsonResponse;

/**
 * "Combine Items" in the Hive Contractors app — the same catalog item priced
 * in several sections made into one line in a section the estimator names.
 * Mirrors App\Livewire\Estimates\EstimateCombineLineItems through the
 * service both share (App\Services\Estimates\EstimateLineItemCombiner): the
 * estimate's total never changes, and an item its sections price
 * differently keeps a line per unit price.
 */
class EstimateCombineItemsController extends Controller
{
    public function index(int $estimate, EstimateLineItemCombiner $combiner): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        return response()->json(['data' => $combiner->candidates($found)]);
    }

    public function store(CombineEstimateItemsRequest $request, int $estimate, EstimateLineItemCombiner $combiner): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        // Only what this estimate itself can combine: a ticked key is client-held.
        $offered = collect($combiner->candidates($found))->pluck('key')->map(fn ($key) => (string) $key)->all();
        $chosen = array_values(array_intersect(array_map('strval', $request->validated('keys')), $offered));

        if ($chosen === []) {
            return response()->json(['message' => 'Those items can no longer be combined. Reload the list.'], 422);
        }

        $result = $combiner->combine($found, $chosen, $request->validated('section_name'));

        return response()->json([
            'data' => [
                'section' => ['id' => $result['section']->id, 'name' => (string) $result['section']->name],
                'combined' => $result['items'],
                'lines' => count($result['combined']),
                'removed' => $result['removed'],
                'emptied' => $result['emptied'],
            ],
        ]);
    }

    private function visibleEstimate(int $id): Estimate
    {
        abort_unless(Estimate::withoutGlobalScopes()->whereKey($id)->exists(), 404);
        $found = Estimate::query()->whereKey($id)->first();
        abort_unless($found, 403, 'You do not have access to this estimate.');

        return $found;
    }
}
