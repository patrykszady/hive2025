<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/team/calls — a call that stays inside Hive, to one
 * teammate (user_id) or the whole crew (none), placed from the app's Team
 * tab. Who may call whom is checked in Api\App\V1\TeamController::call().
 */
class PlaceTeamCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.integer' => 'Pick a teammate to call.',
        ];
    }
}
