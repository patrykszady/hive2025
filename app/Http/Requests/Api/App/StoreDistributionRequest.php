<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/distributions — mirrors App\Livewire\Forms\DistributionForm's
 * own rules exactly (user_id, name).
 */
class StoreDistributionRequest extends FormRequest
{
    /**
     * Real authorization (DistributionPolicy::viewAny, matching
     * DistributionCreate's own render()/save()) happens in the controller.
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
            'user_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'min:3'],
        ];
    }
}
