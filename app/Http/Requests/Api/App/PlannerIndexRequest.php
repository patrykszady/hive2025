<?php

namespace App\Http\Requests\Api\App;

use App\Models\ProjectStatus;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/app/v1/planner — either an explicit `from`/`to` pair (legacy;
 * both given or neither, real Y-m-d dates, `from` on or before `to`, no
 * more than 31 days apart — the agenda loads every candidate project's
 * tasks into memory, so an unbounded range is a real cost, not just a
 * display problem), or the mobile agenda's own tri-state window: `when`
 * ('upcoming' | 'past' | 'all', default 'upcoming' — mirrors the web's
 * App\Livewire\Planner\CardsIndex::$filterDateRange segmented control)
 * plus how many days each direction currently reaches
 * (`days_back`/`days_forward`, each 0-120 — mirrors CardsIndex's
 * previousDaysLoaded/futureDaysLoaded/MAX_DAYS_LOADED paging), which the
 * app grows in "Load more"/"Load earlier" steps instead of paging whole
 * calendar weeks.
 *
 * The rest mirror the web planner's own filter toolbar/mobile Filters modal
 * (App\Livewire\Planner\CardsIndex) — search, project_ids, status_codes,
 * vendor_id, user_ids and mine all narrow the same agenda, same as there.
 */
class PlannerIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string|Closure>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => [
                'nullable',
                'date_format:Y-m-d',
                'required_with:from',
                'after_or_equal:from',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $from = $this->input('from');

                    if (! is_string($from) || ! is_string($value)) {
                        return;
                    }

                    try {
                        $span = Carbon::createFromFormat('!Y-m-d', $from)->diffInDays(Carbon::createFromFormat('!Y-m-d', $value));
                    } catch (\Throwable) {
                        return;
                    }

                    if ($span > 30) {
                        $fail('The range may not span more than 31 days.');
                    }
                },
            ],
            'when' => ['nullable', Rule::in(['upcoming', 'past', 'all'])],
            'days_back' => ['nullable', 'integer', 'min:0', 'max:120'],
            'days_forward' => ['nullable', 'integer', 'min:0', 'max:120'],
            'search' => ['nullable', 'string', 'max:255'],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['integer'],
            'status_codes' => ['nullable', 'array'],
            'status_codes.*' => ['integer', Rule::in(array_column(ProjectStatus::selectableStatuses(), 'code'))],
            'vendor_id' => ['nullable', 'integer'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer'],
            'mine' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.date_format' => 'That is not a valid date.',
            'to.date_format' => 'That is not a valid date.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
            'from.required_with' => 'Give both from and to, or neither.',
            'to.required_with' => 'Give both from and to, or neither.',
        ];
    }
}
