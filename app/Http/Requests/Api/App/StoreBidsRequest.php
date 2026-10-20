<?php

namespace App\Http\Requests\Api\App;

use App\Models\Bid;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/projects/{project}/vendors/{vendor}/bids — same shape as
 * App\Livewire\Bids\BidCreate::rules(). Unlike the web (whose own
 * authorization call is commented out), this endpoint actually enforces
 * App\Policies\BidPolicy::create (Admin, non-1099 vendor).
 */
class StoreBidsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Bid::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bids' => ['required', 'array', 'min:1'],
            'bids.*.id' => ['nullable', 'integer'],
            'bids.*.amount' => ['required', 'numeric', 'regex:/^-?\d+(\.\d{1,2})?$/'],
            'bids.*.type' => ['nullable', 'numeric'],
        ];
    }
}
