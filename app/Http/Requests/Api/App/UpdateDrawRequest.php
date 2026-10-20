<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/app/v1/lien-waivers/draws/{swornStatement} — same row shape as
 * StoreDrawRequest, minus project_id (the existing statement's project
 * can't change). See StoreDrawRequest's docblock for where the business
 * rules live.
 */
class UpdateDrawRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'this_payment' => ['required'],
            'rows' => ['required', 'array'],
            'rows.*.vendor_id' => ['required', 'integer'],
            'rows.*.include' => ['nullable', 'boolean'],
            'rows.*.kind' => ['nullable', 'string'],
            'rows.*.contract' => ['nullable'],
            'rows.*.this_payment' => ['nullable'],
            'rows.*.paid' => ['nullable', 'numeric'],
            'rows.*.is_retail' => ['nullable', 'boolean'],
            'rows.*.is_material' => ['nullable', 'boolean'],
            'rows.*.reuse_signed' => ['nullable', 'boolean'],
            'rows.*.prior_signed_id' => ['nullable', 'integer'],
        ];
    }
}
