<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/vendor-transactions — mirrors
 * App\Livewire\Transactions\VendorTransactionsPanel::createVendorTransaction()'s
 * own validation rules exactly.
 */
class StoreVendorTransactionRequest extends FormRequest
{
    /**
     * Real authorization (TransactionBulkMatchPolicy::viewAny, matching
     * VendorTransactionsPanel's own createVendorTransaction()) happens in
     * the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'desc' => ['required', 'string'],
            'vendor_id' => ['nullable', 'integer'],
            'deposit_check' => ['nullable', 'in:1,2,3,4'],
            'amount_sign' => ['nullable', 'in:1,2'],
            'plaid_inst_id' => ['nullable', 'string'],
            'options' => ['nullable', 'string'],
        ];
    }
}
