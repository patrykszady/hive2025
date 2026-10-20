<?php

namespace App\Http\Requests\Api\App;

use App\Models\Lead;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/app/v1/leads/bulk-status — mirrors
 * App\Livewire\Leads\LeadsIndex::bulkSetStatus()'s own guard: only a status
 * from Lead::selectableStatuses() is accepted, for one or more leads at once.
 */
class BulkUpdateLeadStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(array_column(Lead::selectableStatuses(), 'code'))],
        ];
    }
}
