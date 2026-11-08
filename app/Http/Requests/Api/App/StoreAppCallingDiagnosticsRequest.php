<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A batch of the app's on-device calling-log lines, uploaded once it can
 * next reach the server — see App\Services\Calls\AppCallingDiagnostics.
 */
class StoreAppCallingDiagnosticsRequest extends FormRequest
{
    /** The maximum allowed size of one event's `context`, as JSON. */
    public const MAX_CONTEXT_BYTES = 4096;

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
            'events' => ['required', 'array', 'min:1', 'max:200'],
            'events.*.at' => ['required', 'date'],
            'events.*.level' => ['required', 'string', 'in:info,warn,error'],
            'events.*.message' => ['required', 'string', 'max:500'],
            'events.*.context' => [
                'nullable',
                'array',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_array($value) && strlen((string) json_encode($value)) > self::MAX_CONTEXT_BYTES) {
                        $fail('Context is too large.');
                    }
                },
            ],
            'events.*.call_log_id' => ['nullable', 'integer'],
            'events.*.call_uuid' => ['nullable', 'string', 'max:64'],
        ];
    }
}
