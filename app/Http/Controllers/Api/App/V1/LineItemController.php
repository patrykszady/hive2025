<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreLineItemRequest;
use App\Http\Requests\Api\App\UpdateLineItemRequest;
use App\Http\Resources\Api\App\LineItemResource;
use App\Models\LineItem;
use App\Services\Estimates\EstimateAllowanceSuggestions;
use App\Services\LineItems\InlineAllowanceResolver;
use App\Services\LineItems\LineItemWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's line item catalog — mirrors App\Livewire\LineItems\
 * LineItemsIndex/LineItemCreate exactly via App\Services\LineItems\
 * LineItemWriter, the service both surfaces share. Admin-only, same gate as
 * every other LineItem write (LineItemPolicy::create). LineItem carries no
 * tenant scope of its own, so every query here is manually scoped to the
 * caller's vendor, same as the web.
 */
class LineItemController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request, InlineAllowanceResolver $resolver): JsonResponse
    {
        $this->authorize('viewAny', LineItem::class);

        $vendorId = $request->user()->vendor?->id ?? 0;
        $search = trim((string) $request->query('search', ''));

        $query = LineItem::query()
            ->where('belongs_to_vendor_id', $vendorId)
            ->with(['allowances' => fn ($q) => $q->orderBy('id')])
            ->when($search !== '', function ($q) use ($search) {
                $term = '%'.$search.'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('desc', 'like', $term)
                        ->orWhere('notes', 'like', $term);
                });
            })
            ->latest();

        $page = $query->paginate(self::PER_PAGE);

        // The inline allowance rows shown beneath each catalog row on the
        // web (curated allowances, or a historical fallback when a line
        // item has none) — see InlineAllowanceResolver, shared with
        // LineItemsIndex::inlineAllowances().
        $inlineAllowances = $resolver->resolve($page->getCollection());

        return response()->json([
            'data' => $page->getCollection()->map(fn (LineItem $lineItem) => (new LineItemResource($lineItem))->toArray($request) + [
                'inline_allowances' => ($inlineAllowances->get($lineItem->id) ?? collect())->values()->all(),
            ])->all(),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    public function show(Request $request, int $lineItem): JsonResponse
    {
        $found = $this->visibleLineItem($request, $lineItem);
        $this->authorize('view', $found);

        $found->load(['allowances' => fn ($q) => $q->orderBy('id')]);

        return response()->json(['data' => (new LineItemResource($found))->toArray($request)]);
    }

    /**
     * "Previously used" allowance suggestions for this catalog item —
     * mirrors App\Livewire\LineItems\EstimateLineItemCreate::
     * previousAllowances() via App\Services\Estimates\
     * EstimateAllowanceSuggestions, the service both surfaces share. Used
     * when adding this line item to an estimate, same as the web's
     * autocomplete under each allowance row.
     */
    public function allowanceSuggestions(Request $request, int $lineItem, EstimateAllowanceSuggestions $suggestions): JsonResponse
    {
        $found = $this->visibleLineItem($request, $lineItem);
        $this->authorize('view', $found);

        return response()->json(['data' => $suggestions->forLineItem($found->id)->values()->all()]);
    }

    public function store(StoreLineItemRequest $request): JsonResponse
    {
        $created = app(LineItemWriter::class)->create(
            $request->only(['name', 'desc', 'notes', 'category', 'sub_category', 'unit_type', 'cost']),
            $request->validated('allowances') ?? [],
        );

        $created->load(['allowances' => fn ($q) => $q->orderBy('id')]);

        return response()->json(['data' => (new LineItemResource($created))->toArray($request)], 201);
    }

    public function update(UpdateLineItemRequest $request, int $lineItem): JsonResponse
    {
        // UpdateLineItemRequest::authorize() already confirms LineItemPolicy's
        // 'create' ability — the same gate LineItemForm::update() uses — so
        // only tenant ownership (abort_unless's 404) is checked here.
        $found = $this->visibleLineItem($request, $lineItem);

        $updated = app(LineItemWriter::class)->update(
            $found,
            $request->only(['name', 'desc', 'notes', 'category', 'sub_category', 'unit_type', 'cost']),
            $request->validated('allowances') ?? [],
        );

        $updated->load(['allowances' => fn ($q) => $q->orderBy('id')]);

        return response()->json(['data' => (new LineItemResource($updated))->toArray($request)]);
    }

    /**
     * LineItem carries no tenant scope of its own: every lookup here is
     * manually scoped to the caller's vendor, same as every LineItem query
     * site on the web (see LineItemForm::update()'s own abort_unless).
     */
    private function visibleLineItem(Request $request, int $id): LineItem
    {
        $vendorId = $request->user()->vendor?->id ?? 0;

        abort_unless(LineItem::query()->whereKey($id)->exists(), 404);
        $found = LineItem::query()->where('belongs_to_vendor_id', $vendorId)->whereKey($id)->first();
        abort_unless($found, 403, 'You do not have access to this line item.');

        return $found;
    }
}
