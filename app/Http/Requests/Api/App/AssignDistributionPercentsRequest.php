<?php

namespace App\Http\Requests\Api\App;

use App\Services\Distributions\DistributionProjectAssignment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/app/v1/distributions/projects/{project}/assignment and POST
 * /api/app/v1/distributions/assignment/bulk — mirrors both
 * App\Livewire\Distributions\DistributionProjectsForm::store()'s and
 * ::storeBulk()'s own rules() (same shape for both: every row's percent
 * 0-100, and the set must sum to exactly 100%).
 */
class AssignDistributionPercentsRequest extends FormRequest
{
    /**
     * Real authorization (DistributionPolicy::viewAny — Admin-only) happens
     * in the controller — same pattern as AssociateExpenseRequest.
     */
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
            'percents' => [
                'required',
                'array',
                function ($attribute, $value, $fail) {
                    $sum = app(DistributionProjectAssignment::class)->percentSum(
                        collect((array) $value)->pluck('percent', 'id')->all()
                    );

                    if ($sum !== DistributionProjectAssignment::PERCENT_TOTAL) {
                        $fail('Percent sum must equal to 100%.');
                    }
                },
            ],
            'percents.*.id' => ['required', 'integer'],
            'percents.*.percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<int, int> percent keyed by distribution id, ready for
     *     DistributionProjectAssignment::assign()/assignBulk().
     */
    public function percentsByDistributionId(): array
    {
        return collect($this->validated('percents'))->pluck('percent', 'id')->all();
    }
}
