<?php

namespace App\Http\Requests\Api\App;

use App\Models\Lead;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/app/v1/leads/{lead}/status — mirrors
 * App\Livewire\Leads\LeadsIndex::updateLeadStatus()'s own guard: only a
 * status from Lead::selectableStatuses() is accepted.
 */
class UpdateLeadStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(array_column(Lead::selectableStatuses(), 'code'))],
        ];
    }
}
