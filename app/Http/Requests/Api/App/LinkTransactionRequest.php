<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/expenses/{expense}/link-transaction — mirrors
 * App\Livewire\Expenses\LinkTransaction::link()'s own parameter; the
 * existence/eligibility check (unlinked, on the company's own banks) is
 * left to App\Services\Expenses\TransactionLinkService::link(), same as
 * the Livewire component.
 */
class LinkTransactionRequest extends FormRequest
{
    /**
     * Real authorization (ExpensePolicy::update()) happens in the
     * controller — same pattern as AssociateExpenseRequest.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'transaction_id' => ['required', 'integer'],
        ];
    }
}
