<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\ExportAuditXlsxRequest;
use App\Http\Requests\Api\App\RefreshAuditStatementsRequest;
use App\Http\Requests\Api\App\ShowAuditStatementGapsRequest;
use App\Http\Requests\Api\App\VerifyAuditEwccvRequest;
use App\Services\VendorDocs\AppAuditActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;

/**
 * The audit page actions that App\Services\VendorDocs\AppAuditSummary left
 * web-only (bank-statement gaps/"ask again", "check EWCCV now", and the
 * Excel export) — see App\Services\VendorDocs\AppAuditActions for what
 * each mirrors on App\Livewire\VendorDocs\AuditShow. The certificates PDF
 * and the merged bank-statements PDF stay web-only: both stream multi-MB,
 * third-party-merged binaries built for a browser response, not a mobile
 * fetch client — the app's audit screen opens the web for those.
 */
class VendorDocAuditController extends Controller
{
    public function __construct(private AppAuditActions $actions)
    {
    }

    public function statementGaps(ShowAuditStatementGapsRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'data' => $this->actions->statementGaps(
                $request->user()->vendor,
                $data['start_date'],
                $data['end_date'],
                array_map('intval', $data['bank_account_ids']),
            ),
        ]);
    }

    public function refreshStatements(RefreshAuditStatementsRequest $request, int $bank): JsonResponse
    {
        $data = $request->validated();

        $result = $this->actions->refreshBank(
            $request->user()->vendor,
            $bank,
            $data['start_date'],
            $data['end_date'],
            array_map('intval', $data['bank_account_ids']),
            $request->user(),
        );

        return response()->json(['data' => $result]);
    }

    public function verifyEwccv(VerifyAuditEwccvRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->actions->verifyEwccv(
            $request->user()->vendor,
            $request->user(),
            $data['start_date'],
            $data['end_date'],
            $data['type'] ?? null,
            array_map('intval', $data['bank_account_ids'] ?? []),
        );

        return response()->json(['data' => $result]);
    }

    /** A fresh, 10-minute signed link to the audit's Excel export. */
    public function exportXlsxUrl(ExportAuditXlsxRequest $request): JsonResponse
    {
        $data = $request->validated();
        $expiresAt = now()->addMinutes(10);

        return response()->json([
            'data' => [
                'url' => URL::temporarySignedRoute('vendor-docs.audit.xlsx.signed', $expiresAt, [
                    'vendor' => $request->user()->vendor->id,
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'],
                    'type' => $data['type'] ?? null,
                    'bank_account_ids' => array_map('intval', $data['bank_account_ids'] ?? []),
                ]),
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }
}
