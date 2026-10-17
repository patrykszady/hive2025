<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PlaceAppCallRequest extends FormRequest
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
            'to' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, \Closure $fail): void {
                $digits = strlen((string) preg_replace('/\D/', '', (string) $value));
                if ($digits < 10 || $digits > 15) {
                    $fail('Enter a full phone number.');
                }
            }],
            // Which of the caller's phones Hive rings first: the app (default),
            // or their cell (Expo Go, before the app has its own calling).
            'ring' => ['nullable', 'string', 'in:app,cell'],
        ];
    }
}
