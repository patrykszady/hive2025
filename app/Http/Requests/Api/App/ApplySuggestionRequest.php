<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/transactions/apply-suggestion — the app API's
 * equivalent of App\Livewire\Transactions\MatchVendor::applySuggestion():
 * accept the AI's suggestion for a card (as returned by suggest-vendor),
 * optionally overridden by a hand-typed "Match As".
 */
class ApplySuggestionRequest extends FormRequest
{
    /**
     * Real authorization (TransactionBulkMatchPolicy::viewAny, matching
     * MatchVendor's own render()/applySuggestion()) happens in the
     * controller.
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
            'descriptor' => ['required', 'string'],
            'match_desc' => ['nullable', 'string'],
            'suggestion' => ['required', 'array'],
            'suggestion.vendor_name' => ['required', 'string'],
            'suggestion.existing_vendor_id' => ['nullable', 'integer'],
            'suggestion.website' => ['nullable', 'string'],
            'suggestion.city' => ['nullable', 'string'],
            'suggestion.state' => ['nullable', 'string'],
            'suggestion.match_desc' => ['nullable', 'string'],
            'suggestion.confidence' => ['nullable', 'string'],
        ];
    }
}
