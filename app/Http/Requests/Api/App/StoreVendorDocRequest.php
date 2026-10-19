<?php

namespace App\Http\Requests\Api\App;

use App\Models\VendorDoc;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/app/v1/vendor-docs/{vendor} — mirrors VendorDocCreate's `doc_file` rule. */
class StoreVendorDocRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', VendorDoc::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'doc_file' => ['required', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
        ];
    }
}
