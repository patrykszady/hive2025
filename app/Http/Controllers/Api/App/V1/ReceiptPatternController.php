<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreReceiptPatternRequest;
use App\Http\Requests\Api\App\UpdateReceiptPatternRequest;
use App\Http\Resources\Api\App\ReceiptPatternResource;
use App\Models\Receipt;
use App\Models\Vendor;
use App\Services\Receipts\ReceiptPatternDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Receipt Patterns screen — the email receipt-PARSING setup
 * (App\Livewire\Receipts\ReceiptsIndex), via App\Services\Receipts\
 * ReceiptPatternDirectory. Patryk (user id 1) only, same convention as
 * Agents/AgentController — every method here, including reads, aborts
 * for anyone else.
 *
 * The web's own /receipts route doesn't gate reads this way (it sits
 * behind the ordinary auth+vendor.access group, so any team member could
 * open it by URL — only its writes check id 1); but nobody else on the
 * team knows that URL exists, since it has no sidebar entry. Surfacing it
 * from the app's Manage hub makes it discoverable, so reads are locked to
 * id 1 here too rather than handing the whole team a config screen the
 * web only ever shows to the one admin who already knows where to look.
 */
class ReceiptPatternController extends Controller
{
    public function __construct(private ReceiptPatternDirectory $directory)
    {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->id === 1, 403);

        $search = trim((string) $request->query('search', ''));
        $page = $this->directory->list($search, (int) $request->query('page', 1));

        return response()->json([
            'data' => ReceiptPatternResource::collection($page->getCollection())->toArray($request),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function vendors(Request $request): JsonResponse
    {
        abort_unless($request->user()->id === 1, 403);

        return response()->json([
            'data' => $this->directory->vendors()->map(fn (Vendor $vendor) => [
                'id' => $vendor->id,
                'business_name' => $vendor->business_name,
            ])->values()->all(),
        ]);
    }

    public function show(Request $request, Receipt $receipt): JsonResponse
    {
        abort_unless($request->user()->id === 1, 403);

        return response()->json(['data' => (new ReceiptPatternResource($receipt->loadMissing('vendor')))->toArray($request)]);
    }

    public function store(StoreReceiptPatternRequest $request): JsonResponse
    {
        $receipt = $this->directory->create($request->validated());

        return response()->json(['data' => (new ReceiptPatternResource($receipt->loadMissing('vendor')))->toArray($request)], 201);
    }

    public function update(UpdateReceiptPatternRequest $request, Receipt $receipt): JsonResponse
    {
        $updated = $this->directory->update($receipt, $request->validated());

        return response()->json(['data' => (new ReceiptPatternResource($updated->loadMissing('vendor')))->toArray($request)]);
    }

    public function destroy(Request $request, Receipt $receipt): JsonResponse
    {
        abort_unless($request->user()->id === 1, 403);

        $this->directory->delete($receipt);

        return response()->json(['data' => ['deleted' => true]]);
    }
}
