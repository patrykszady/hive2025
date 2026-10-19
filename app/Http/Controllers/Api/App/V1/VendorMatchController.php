<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\UpdateVendorMatchRequest;
use App\Models\Distribution;
use App\Models\TransactionBulkMatch;
use App\Models\Vendor;
use App\Services\ReceiptAccounts\AppReceiptAccountDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Vendor Match screen — see
 * App\Livewire\ReceiptAccounts\{ReceiptAccountsIndex,
 * ReceiptAccountVendorCreate} (the web pages this mirrors) and
 * App\Services\ReceiptAccounts\AppReceiptAccountDirectory.
 */
class VendorMatchController extends Controller
{
    public function __construct(private AppReceiptAccountDirectory $directory)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);
        $company = $request->user()->vendor;

        return response()->json([
            'data' => $this->directory->vendors($company)->map(fn (Vendor $vendor) => [
                'id' => $vendor->id,
                'business_name' => $vendor->business_name,
                'has_receipt_account' => $vendor->receipt_account !== null,
                'bulk_match_count' => $vendor->transactions_bulk_match_count ?? 0,
                'receipts_count' => $vendor->receipts_count ?? 0,
            ])->values()->all(),
        ]);
    }

    public function newVendors(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        return response()->json([
            'data' => $this->directory->newVendors($request->user()->vendor)
                ->map(fn (Vendor $vendor) => ['id' => $vendor->id, 'business_name' => $vendor->business_name])
                ->values()->all(),
        ]);
    }

    public function show(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorize('create', TransactionBulkMatch::class);
        $company = $request->user()->vendor;

        $detail = $this->directory->detail($vendor, $company);

        return response()->json([
            'data' => [
                'vendor' => ['id' => $vendor->id, 'business_name' => $vendor->business_name],
                'credential_fields' => $detail['credential_fields'],
                'credential_values' => $detail['credential_values'],
                'bulk_matches' => $detail['bulk_matches'],
                'vendor_transactions' => $detail['vendor_transactions'],
                'distributions' => $detail['distributions']->map(fn (Distribution $d) => ['id' => $d->id, 'name' => $d->name])->values()->all(),
            ],
        ]);
    }

    public function update(UpdateVendorMatchRequest $request, Vendor $vendor): JsonResponse
    {
        $company = $request->user()->vendor;
        $data = $request->validated();

        $vendor->loadMissing('receipts');
        $credentialFields = (array) ($vendor->receipts->first()?->options['credential_fields'] ?? []);

        $this->directory->save(
            $vendor,
            $company,
            $credentialFields,
            $data['credential_values'] ?? [],
            $data['bulk_matches'] ?? [],
        );

        $detail = $this->directory->detail($vendor->refresh(), $company);

        return response()->json([
            'data' => [
                'vendor' => ['id' => $vendor->id, 'business_name' => $vendor->business_name],
                'credential_fields' => $detail['credential_fields'],
                'credential_values' => $detail['credential_values'],
                'bulk_matches' => $detail['bulk_matches'],
                'vendor_transactions' => $detail['vendor_transactions'],
                'distributions' => $detail['distributions']->map(fn (Distribution $d) => ['id' => $d->id, 'name' => $d->name])->values()->all(),
            ],
        ]);
    }
}
