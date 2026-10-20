<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/receipt-patterns — the same rules
 * App\Livewire\Receipts\ReceiptsIndex::rules() enforces, Patryk (user id
 * 1) only, exactly as its store()/edit()/delete().
 */
class StoreReceiptPatternRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->id === 1;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'from_address' => ['required', 'string', 'max:255'],
            'from_subjects' => ['nullable', 'array'],
            'from_subjects.*' => ['nullable', 'string', 'max:255'],
            'receipt_type' => ['required', 'in:1,2'],
            'options' => ['nullable', 'array'],
            'options.receipt_start' => ['nullable', 'string'],
            'options.receipt_end' => ['nullable', 'string'],
            'options.receipt_start_offset' => ['nullable', 'string'],
            'options.receipt_image_regex' => ['nullable', 'string'],
            'options.query_fields' => ['nullable', 'string'],
            'options.document_model' => ['nullable', 'string'],
            'options.ocr' => ['nullable', 'string'],
            'options.ocr_type' => ['nullable', 'string'],
            'options.pdf_html' => ['nullable'],
            'options.image_extension' => ['nullable', 'string'],
        ];
    }
}
