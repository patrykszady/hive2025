<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/transactions/suggest-vendor — the app API's equivalent
 * of App\Livewire\Transactions\MatchVendor::suggestVendor(): ask the AI
 * (VendorSuggestionService) who the merchant behind this card's descriptor
 * is.
 */
class SuggestVendorRequest extends FormRequest
{
    /**
     * Real authorization (TransactionBulkMatchPolicy::viewAny, matching
     * MatchVendor's own render()/suggestVendor()) happens in the
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
        ];
    }
}
