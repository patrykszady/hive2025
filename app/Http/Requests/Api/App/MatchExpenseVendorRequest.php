<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/transactions/match-expense — one row of
 * App\Livewire\Transactions\MatchVendor::store_expense_vendors(), applied
 * to a single receipt-expense card.
 */
class MatchExpenseVendorRequest extends FormRequest
{
    /**
     * Real authorization (TransactionBulkMatchPolicy::viewAny, matching
     * MatchVendor's own render()/store_expense_vendors()) happens in the
     * controller.
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
            'merchant_name' => ['required', 'string'],
            'match_desc' => ['required', 'string'],
            'vendor_id' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== 'NEW' && ! ctype_digit((string) $value)) {
                        $fail('Choose a vendor.');
                    }
                },
            ],
        ];
    }
}
