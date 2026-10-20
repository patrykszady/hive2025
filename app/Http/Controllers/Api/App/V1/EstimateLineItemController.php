<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\CreditEstimateLineItemRequest;
use App\Http\Requests\Api\App\MoveEstimateLineItemRequest;
use App\Http\Requests\Api\App\StoreEstimateLineItemRequest;
use App\Http\Requests\Api\App\UpdateEstimateLineItemRequest;
use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateLineItemAllowance;
use App\Models\LineItem;
use App\Services\Estimates\EstimateLineItemWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An estimate's line items — mirrors App\Livewire\Forms\
 * EstimateLineItemForm::store()/update() exactly via
 * App\Services\Estimates\EstimateLineItemWriter, the service both surfaces
 * share. Authorization for store/update is the LineItemPolicy 'create'
 * ability (see the Form Requests); destroy stays gated on the estimate's own
 * 'update' ability, matching App\Livewire\LineItems\
 * EstimateLineItemCreate::removeFromEstimate() exactly.
 */
class EstimateLineItemController extends Controller
{
    public function store(StoreEstimateLineItemRequest $request, int $estimate): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);

        $section = $found->estimate_sections()->findOrFail($request->validated('section_id'));

        // LineItem carries no tenant scope of its own: the catalog id must
        // belong to this vendor.
        $lineItem = LineItem::where('belongs_to_vendor_id', $request->user()->vendor?->id ?? 0)
            ->findOrFail($request->validated('line_item_id'));

        $quantity = (float) $request->validated('quantity');
        $cost = (float) $request->validated('cost');

        $created = app(EstimateLineItemWriter::class)->create(
            $found->id,
            $section,
            $lineItem,
            [
                'category' => $request->validated('category'),
                'sub_category' => $request->validated('sub_category'),
                'unit_type' => $request->validated('unit_type'),
                'quantity' => $quantity,
                'cost' => $cost,
                'total' => round($quantity * $cost, 2),
                'desc' => $request->validated('desc'),
                'notes' => $request->validated('notes'),
            ],
            $request->validated('allowances') ?? [],
        );

        return response()->json(['data' => $this->lineItemArray($created->fresh('allowances'))], 201);
    }

    public function update(UpdateEstimateLineItemRequest $request, int $estimate, int $lineItem): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);

        $existing = $found->estimate_line_items()->findOrFail($lineItem);
        $section = $found->estimate_sections()->findOrFail($request->validated('section_id'));

        $quantity = (float) $request->validated('quantity');
        $cost = (float) $request->validated('cost');

        $updated = app(EstimateLineItemWriter::class)->update(
            $existing,
            $section,
            [
                'category' => $request->validated('category'),
                'sub_category' => $request->validated('sub_category'),
                'unit_type' => $request->validated('unit_type'),
                'quantity' => $quantity,
                'cost' => $cost,
                'total' => round($quantity * $cost, 2),
                'desc' => $request->validated('desc'),
                'notes' => $request->validated('notes'),
            ],
            $request->validated('allowances') ?? [],
        );

        return response()->json(['data' => $this->lineItemArray($updated->fresh('allowances'))]);
    }

    public function destroy(Request $request, int $estimate, int $lineItem): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $existing = $found->estimate_line_items()->findOrFail($lineItem);
        $existing->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Mirrors App\Livewire\Estimates\EstimateShow::lineItemRestore() via EstimateSectionWriter::restoreLineItem(). */
    public function restore(Request $request, int $estimate, int $lineItem, \App\Services\Estimates\EstimateSectionWriter $writer): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $existing = $found->estimate_line_items()->onlyTrashed()->findOrFail($lineItem);
        $writer->restoreLineItem($existing);

        return response()->json(['data' => $this->lineItemArray($existing->fresh('allowances'))]);
    }

    /**
     * Reorders a line item, optionally into a different section — mirrors
     * App\Livewire\Estimates\EstimateShow::sort_line_item() exactly via
     * App\Services\Estimates\EstimateLineItemWriter, the service both
     * surfaces share.
     */
    public function move(MoveEstimateLineItemRequest $request, int $estimate, int $lineItem, EstimateLineItemWriter $writer): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $existing = $found->estimate_line_items()->findOrFail($lineItem);

        $sectionId = $request->validated('section_id');
        // The destination must be one of this estimate's own sections too.
        $section = $sectionId ? $found->estimate_sections()->findOrFail($sectionId) : null;

        $moved = $writer->moveLineItem($existing, $request->validated('position'), $section);

        return response()->json(['data' => $this->lineItemArray($moved->fresh('allowances'))]);
    }

    /**
     * Credits a line item locked into the signed contract — a locked row's
     * "Credit to change order" action, mirroring App\Livewire\LineItems\
     * EstimateLineItemCreate::creditToChangeOrder() exactly via
     * App\Services\Estimates\EstimateLineItemWriter, the service both
     * surfaces share. The web only ever submits the item's own current
     * quantity/cost here (its form fields are disabled once a row is
     * locked), so the app does the same rather than taking them as input.
     */
    public function credit(CreditEstimateLineItemRequest $request, int $estimate, int $lineItem, EstimateLineItemWriter $writer): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);

        $existing = $found->estimate_line_items()->findOrFail($lineItem);

        $credit = $writer->creditToChangeOrder(
            $existing,
            (float) ($request->validated('quantity') ?? $existing->quantity),
            (float) ($request->validated('cost') ?? $existing->cost),
        );

        abort_unless($credit, 422, 'This line item can\'t be credited.');

        return response()->json(['data' => $this->lineItemArray($credit->fresh('allowances'))], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function lineItemArray(EstimateLineItem $lineItem): array
    {
        return [
            'id' => $lineItem->id,
            'line_item_id' => $lineItem->line_item_id,
            'section_id' => $lineItem->section_id,
            'name' => $lineItem->name,
            'category' => $lineItem->category,
            'sub_category' => $lineItem->sub_category,
            'unit_type' => $lineItem->unit_type,
            'quantity' => (float) $lineItem->quantity,
            'cost' => (float) $lineItem->cost,
            'total' => round((float) $lineItem->total, 2),
            'desc' => $lineItem->desc,
            'notes' => $lineItem->notes,
            'allowances' => $lineItem->allowances
                ->map(fn (EstimateLineItemAllowance $allowance) => [
                    'id' => $allowance->id,
                    'description' => $allowance->description,
                    'pricing_mode' => $allowance->pricing_mode ?? ($allowance->unit_amount !== null ? 'per_unit' : 'lump_sum'),
                    'unit_amount' => $allowance->unit_amount !== null ? (float) $allowance->unit_amount : null,
                    'amount' => $allowance->amount !== null ? (float) $allowance->amount : null,
                ])
                ->values()
                ->all(),
        ];
    }

    private function visibleEstimate(int $id): Estimate
    {
        abort_unless(Estimate::withoutGlobalScopes()->whereKey($id)->exists(), 404);
        $found = Estimate::query()->whereKey($id)->first();
        abort_unless($found, 403, 'You do not have access to this estimate.');

        return $found;
    }
}
