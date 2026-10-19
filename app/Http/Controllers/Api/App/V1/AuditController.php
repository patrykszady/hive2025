<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\ShowAuditSummaryRequest;
use App\Models\VendorDoc;
use App\Services\VendorDocs\AppAuditSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A read-only summary of the web's /vendor_docs/audit page — see
 * App\Services\VendorDocs\AppAuditSummary's docblock for what stays
 * web-only and why.
 */
class AuditController extends Controller
{
    public function __construct(private AppAuditSummary $summary)
    {
    }

    public function banks(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VendorDoc::class);

        return response()->json(['data' => $this->summary->banks($request->user()->vendor)]);
    }

    public function show(ShowAuditSummaryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $summary = $this->summary->summary(
            $request->user()->vendor,
            $request->user(),
            $data['start_date'],
            $data['end_date'],
            $data['type'] ?? null,
            array_map('intval', $data['bank_account_ids'] ?? []),
        );

        return response()->json(['data' => $summary]);
    }
}
