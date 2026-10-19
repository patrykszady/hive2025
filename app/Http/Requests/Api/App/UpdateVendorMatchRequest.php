<?php

namespace App\Http\Requests\Api\App;

use App\Models\TransactionBulkMatch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/app/v1/vendor-match/{vendor} — mirrors
 * ReceiptAccountVendorCreate::rules()/store(), reshaped for a plain
 * `credential_values` map (keyed by the field defs GET already returned)
 * instead of Livewire's bound array property.
 */
class UpdateVendorMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', TransactionBulkMatch::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'credential_values' => ['array'],
            'credential_values.*' => ['nullable', 'string'],
            'bulk_matches' => ['array'],
            'bulk_matches.*.id' => ['nullable', 'integer'],
            'bulk_matches.*.amount' => ['nullable', 'numeric'],
            'bulk_matches.*.distribution_id' => ['nullable', 'integer', 'exists:distributions,id'],
            'bulk_matches.*.options.amount_type' => ['required', 'in:ANY,=,>=,<=,>,<'],
            'bulk_matches.*.options.desc' => ['nullable', 'string', 'max:255'],
            'bulk_matches.*.splits' => ['nullable', 'array'],
            'bulk_matches.*.splits.*.amount' => ['nullable', 'numeric'],
            'bulk_matches.*.splits.*.amount_type' => ['nullable', 'in:$,%'],
            'bulk_matches.*.splits.*.distribution_id' => ['nullable', 'integer', 'exists:distributions,id'],
        ];
    }
}
