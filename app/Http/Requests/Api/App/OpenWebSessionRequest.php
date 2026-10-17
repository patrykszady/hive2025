<?php

namespace App\Http\Requests\Api\App;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OpenWebSessionRequest extends FormRequest
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
     * A relative, same-site path only: must start with exactly one "/" (no
     * "//" — a protocol-relative URL — and no backslash), and never carry a
     * scheme. Anything else is rejected rather than guessed at.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'path' => [
                'required',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! is_string($value)
                        || ! str_starts_with($value, '/')
                        || str_starts_with($value, '//')
                        || str_contains($value, '\\')
                        || str_contains($value, '://')
                    ) {
                        $fail('The path must be a relative path on this site.');
                    }
                },
            ],
        ];
    }
}
