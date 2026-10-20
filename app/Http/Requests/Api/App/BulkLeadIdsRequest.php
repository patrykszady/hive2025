<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/leads/bulk-delete-impact and /leads/bulk-delete — mirrors
 * App\Livewire\Leads\LeadsIndex::bulkDeleteImpact()/bulkDelete()'s own
 * input: a batch of lead ids, selected the same way the web's checkboxes
 * build $this->selected.
 */
class BulkLeadIdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string|\Closure>
     */
    public function rules(): array
    {
        return [
            'lead_ids' => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['integer'],
        ];
    }
}
