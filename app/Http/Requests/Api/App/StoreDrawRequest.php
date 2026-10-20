<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/lien-waivers/draws — structural validation only (types
 * present, right shape). The business rules that must stay identical to the
 * web (the draw amount, each row's money fields, "kind of work" required
 * per included vendor) live in App\Services\LienWaivers\DrawPackageService::
 * buildValidator(), which the controller runs on top of this.
 */
class StoreDrawRequest extends FormRequest
{
    /**
     * Real authorization (LienWaiverPolicy's create) happens in the
     * controller, same pattern as StoreLienWaiverRequest.
     */
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
            'project_id' => ['required', 'integer'],
            'this_payment' => ['required'],
            'rows' => ['required', 'array', 'min:1'],
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
