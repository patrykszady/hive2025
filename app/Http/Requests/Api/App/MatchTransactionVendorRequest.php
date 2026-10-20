<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/transactions/match — one row of
 * App\Livewire\Transactions\MatchVendor::store(), applied to a single
 * merchant card. 'vendor_id' is either a numeric vendor id, 'NEW' (create
 * a retail vendor named $match_desc), or one of the non-vendor codes the
 * web's card offers (DEPOSIT/CHECK/TRANSFER/CASH).
 */
class MatchTransactionVendorRequest extends FormRequest
{
    /**
     * Real authorization (TransactionBulkMatchPolicy::viewAny, matching
     * MatchVendor's own render()/store()) happens in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'descriptor' => ['required', 'string'],
            'match_desc' => ['required', 'string'],
            'vendor_id' => [
                'required',
                'string',
                // Either a non-vendor code, or a numeric vendor id.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! in_array($value, ['NEW', 'DEPOSIT', 'CHECK', 'TRANSFER', 'CASH'], true) && ! ctype_digit((string) $value)) {
                        $fail('Choose a vendor or a transaction type.');
                    }
                },
            ],
            'amount_sign' => ['nullable', 'in:1,2'],
            'bank_specific' => ['nullable', 'boolean'],
        ];
    }
}
