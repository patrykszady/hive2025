<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\GenerateEstimateAiDraftRequest;
use App\Models\Estimate;
use App\Models\EstimateAiDraft;
use App\Models\EstimateLineItem;
use App\Services\Estimates\EstimateAiDraftWriter;
use App\Services\Estimates\FloorplanParser;
use App\Services\EstimateAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The AI estimate drafter — reuses App\Services\EstimateAIService exactly
 * (the same catalog-constrained Claude call App\Livewire\Estimates\
 * EstimateAIGenerator uses), but synchronously and without the web's
 * per-line streaming: the app gets the finished draft in one response
 * instead of watching rows arrive one at a time. Floorplan uploads are
 * images only (expo-image-picker has no document/CSV picker in this Expo
 * Go build), measured by the same App\Services\Estimates\FloorplanParser
 * the web's EstimateAIGenerator uses.
 */
class EstimateAiController extends Controller
{
    public function draft(GenerateEstimateAiDraftRequest $request, int $estimate, int $section, FloorplanParser $parser): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $sectionModel = $found->estimate_sections()->findOrFail($section);

        $files = $request->file('floorplans') ?? [];
        $labels = array_map(fn ($i) => 'Floor '.($i + 1), array_keys($files));
        $floorplanData = $files !== [] ? $parser->parseFloorplans($files, $labels) : null;

        $service = app(EstimateAIService::class);
        $result = $service->generateEstimate(
            inquiry: $request->validated('inquiry'),
            floorplanData: $floorplanData,
            vendorId: $found->belongs_to_vendor_id ?? 1,
        );

        if (! $result['success']) {
            return response()->json(['message' => $result['error'] ?? 'Failed to generate the draft.'], 422);
        }

        $created = $service->applyToEstimate($found, $sectionModel, $result['line_items']);

        if ($created === []) {
            return response()->json(['message' => 'Nothing was drafted. Describe the work in more detail and try again.'], 422);
        }

        $sectionModel->forceFill(['ai_inquiry' => $request->validated('inquiry'), 'ai_scope' => $result['reasoning'] ?: null])->save();

        $draft = EstimateAiDraft::create([
            'vendor_id' => $found->belongs_to_vendor_id ?? 1,
            'estimate_id' => $found->id,
            'section_id' => $sectionModel->id,
            'user_id' => $request->user()->id,
            'request_id' => $result['meta']['request_id'] ?? null,
            'model' => $result['meta']['model'] ?? null,
            'inquiry' => $request->validated('inquiry'),
            'floorplan' => $result['meta']['floorplan'] ?? null,
            'reasoning' => $result['reasoning'] ?: null,
            'drafted_items' => collect($created)->map(fn (EstimateLineItem $line) => [
                'estimate_line_item_id' => $line->id,
                'line_item_id' => $line->line_item_id,
                'name' => $line->name,
                'quantity' => (float) $line->quantity,
                'unit_type' => $line->unit_type,
                'cost' => (float) $line->cost,
            ])->all(),
            'usage' => $result['meta']['usage'] ?? null,
            'stop_reason' => $result['meta']['stop_reason'] ?? null,
            'status' => EstimateAiDraft::DRAFTED,
        ]);

        return response()->json([
            'data' => [
                'draft_id' => $draft->id,
                'reasoning' => $result['reasoning'] ?? '',
                'section' => ['id' => $sectionModel->id, 'total' => round((float) $sectionModel->fresh()->total, 2)],
                'line_items' => collect($created)->map(fn (EstimateLineItem $line) => [
                    'id' => $line->id,
                    'line_item_id' => $line->line_item_id,
                    'name' => $line->name,
                    'category' => $line->category,
                    'sub_category' => $line->sub_category,
                    'unit_type' => $line->unit_type,
                    'quantity' => (float) $line->quantity,
                    'cost' => (float) $line->cost,
                    'total' => round((float) $line->total, 2),
                    'desc' => $line->desc,
                    'notes' => $line->notes,
                ])->values()->all(),
            ],
        ], 201);
    }

    /**
     * Discards a draft — every line item it added force-deleted, the
     * section's recorded inquiry/scope cleared. Mirrors
     * App\Livewire\Estimates\EstimateAIGenerator::discardDraft() exactly via
     * App\Services\Estimates\EstimateAiDraftWriter, the service both
     * surfaces share. Unlike the web (which discards its live, possibly
     * already-edited row list), the app discards exactly what the draft
     * itself recorded — the app has no intermediate "remove one row" step
     * between drafting and discarding.
     */
    public function discard(Request $request, int $estimate, int $draft, EstimateAiDraftWriter $writer): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $draftModel = EstimateAiDraft::where('estimate_id', $found->id)->findOrFail($draft);

        $lineItemIds = collect($draftModel->drafted_items ?? [])->pluck('estimate_line_item_id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        $writer->discard($found, (int) $draftModel->section_id, $draftModel->id, $lineItemIds);

        return response()->json(['data' => ['discarded' => true]]);
    }

    private function visibleEstimate(int $id): Estimate
    {
        abort_unless(Estimate::withoutGlobalScopes()->whereKey($id)->exists(), 404);
        $found = Estimate::query()->whereKey($id)->first();
        abort_unless($found, 403, 'You do not have access to this estimate.');

        return $found;
    }
}
