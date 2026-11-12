<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A batch of JavaScript errors the Hive Contractors app caught on the
 * phone — see App\Services\Diagnostics\AppErrorReports.
 */
class StoreAppErrorReportsRequest extends FormRequest
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
            'errors' => ['required', 'array', 'min:1', 'max:50'],
            'errors.*.at' => ['required', 'date'],
            'errors.*.kind' => ['required', 'string', 'in:error,promise,render'],
            'errors.*.fatal' => ['required', 'boolean'],
            'errors.*.message' => ['required', 'string', 'max:500'],
            'errors.*.stack' => ['nullable', 'string', 'max:4000'],
            'errors.*.screen' => ['nullable', 'string', 'max:200'],
            'errors.*.build' => ['nullable', 'string', 'max:60'],
        ];
    }
}
