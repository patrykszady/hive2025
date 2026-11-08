<?php

namespace App\Http\Requests\Api\App;

use App\Models\PushToken;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registers (or re-registers, on reinstall or token rotation) the calling
 * device's Expo push token — see App\Http\Controllers\Api\App\V1\
 * PushTokenController::store().
 */
class StorePushTokenRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:'.PushToken::PLATFORM_IOS.','.PushToken::PLATFORM_ANDROID],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
