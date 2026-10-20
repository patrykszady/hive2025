<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/projects/{project}/frames/{frame}/move — same two
 * directions as the web studio's "Move frame earlier/later" arrows
 * (App\Livewire\Projects\TimelapseStudio::moveFrame()).
 */
class MoveProjectFrameRequest extends FormRequest
{
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
            'direction' => ['required', 'integer', 'in:-1,1'],
        ];
    }
}
