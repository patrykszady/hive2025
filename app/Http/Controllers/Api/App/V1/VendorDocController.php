<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreVendorDocRequest;
use App\Http\Resources\Api\App\VendorDocResource;
use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Services\VendorDocs\AppVendorDocDirectory;
use App\Services\VendorDocs\VendorDocRequester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Vendor Documents screen — see
 * App\Livewire\VendorDocs\{VendorDocsIndex,VendorDocsCard,VendorDocCreate}
 * (the web pages this mirrors) and
 * App\Services\VendorDocs\AppVendorDocDirectory.
 */
class VendorDocController extends Controller
{
    public function __construct(private AppVendorDocDirectory $directory)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VendorDoc::class);

        $vendors = $this->directory->vendors();
        $counts = $this->directory->docTypeCounts($vendors);

        return response()->json([
            'data' => $vendors->map(fn (Vendor $vendor) => [
                'id' => $vendor->id,
                'business_name' => $vendor->business_name,
                'business_type' => $vendor->business_type,
                'doc_type_count' => $counts[$vendor->id] ?? 0,
                'expense_count' => $vendor->expense_count,
                'expired_docs' => collect($vendor->vendor_docs)->contains(fn (VendorDoc $doc) => $doc->expiration_date?->lte(today())),
            ])->values()->all(),
        ]);
    }

    public function show(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorize('viewAny', VendorDoc::class);

        ['current' => $current, 'previous' => $previous] = $this->directory->docsForVendor($vendor);

        return response()->json([
            'data' => [
                'vendor' => ['id' => $vendor->id, 'business_name' => $vendor->business_name],
                'current' => $current->map(fn (VendorDoc $doc) => (new VendorDocResource($doc))->toArray($request))->values()->all(),
                'previous' => $previous->map(fn (VendorDoc $doc) => (new VendorDocResource($doc))->toArray($request))->values()->all(),
            ],
        ]);
    }

    public function store(StoreVendorDocRequest $request, Vendor $vendor): JsonResponse
    {
        $result = $this->directory->store($vendor, (int) $request->user()->vendor->id, $request->file('doc_file'));

        if ($result === false) {
            return response()->json(['message' => 'Unable to match vendor information from the document. Please verify the document contains correct vendor details.'], 422);
        }

        return response()->json([
            'data' => ['result' => $result],
            'message' => match ($result) {
                'duplicate' => 'This document appears to be a duplicate. No new policies were added.',
                'updated' => 'Existing policy records were updated to reference the new file.',
                default => 'Vendor document added.',
            },
        ], $result === true ? 201 : 200);
    }

    /**
     * "Request Documents" — the card's second button
     * (VendorDocCreate::requestDocument(), via the shared VendorDocRequester
     * so both surfaces queue the exact same emails). Only ever does
     * anything when this vendor has an expired document of some type.
     */
    public function request(Request $request, Vendor $vendor, VendorDocRequester $requester): JsonResponse
    {
        $this->authorize('create', VendorDoc::class);

        $result = $requester->requestExpired($vendor, $request->user()->vendor);

        return response()->json([
            'data' => $result,
            'message' => $result['sent']
                ? 'License and insurance requests were routed to the appropriate recipients.'
                : 'There are no expired vendor documents to request right now.',
        ]);
    }
}
