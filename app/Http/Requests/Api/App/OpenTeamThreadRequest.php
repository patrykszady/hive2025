<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/team/threads — open (making it on first use) a Hive-only
 * team conversation: with one teammate (user_id), or the whole team (none).
 * Who may open which is checked in Api\App\V1\TeamController::open().
 */
class OpenTeamThreadRequest extends FormRequest
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
            'user_id.integer' => 'Pick a teammate to message.',
        ];
    }
}
