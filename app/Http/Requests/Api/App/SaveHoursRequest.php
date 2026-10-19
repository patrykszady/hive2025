<?php

namespace App\Http\Requests\Api\App;

use App\Models\Hour;
use App\Services\Hours\AppHoursDay;
use App\Services\Hours\HourWeekLock;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/app/v1/hours — a day's worth of project hours, exactly as
 * HourCreate's "Add"/"Edit Daily Hours" forms accept them: each project's
 * hours nullable|numeric|min:0|max:16 (HourForm::rules()), the date may not
 * fall inside a week already confirmed into a Timesheet (HourWeekLock — the
 * web enforces this by disabling the date on its calendar; there is no such
 * UI here, so the server enforces it directly), and a day with no existing
 * entries yet needs at least one positive entry (HourCreate::save()'s
 * "Daily Hours need at least one entry").
 */
class SaveHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Hour::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string|Closure>
     */
    public function rules(): array
    {
        return [
            'date' => [
                'required',
                'date_format:Y-m-d',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    $minDate = app(AppHoursDay::class)->minDate();

                    if (app(HourWeekLock::class)->isLocked($this->user(), $value, $minDate)) {
                        $fail('That week has already been confirmed into a timesheet and can no longer be changed here.');
                    }
                },
            ],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.project_id' => ['required', 'integer'],
            'entries.*.hours' => ['nullable', 'numeric', 'min:0', 'max:16'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entries.required' => 'Daily Hours need at least one entry.',
            'entries.*.hours.max' => 'Hours may not be more than 16 per project.',
        ];
    }

    /**
     * A fresh day (no hours logged yet) needs at least one positive entry —
     * mirrors HourCreate::save()'s hours_count==0 guard. An already-started
     * day (editing) may zero every entry out, same as HourCreate::edit().
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            $date = $this->input('date');

            if (! is_string($date) || $validator->errors()->has('date')) {
                return;
            }

            $hasExisting = Hour::where('user_id', $this->user()->id)->where('date', $date)->exists();

            if ($hasExisting) {
                return;
            }

            $entries = (array) $this->input('entries', []);
            $hasPositive = collect($entries)->contains(fn ($entry) => is_numeric($entry['hours'] ?? null) && (float) $entry['hours'] > 0);

            if (! $hasPositive) {
                $validator->errors()->add('entries', 'Daily Hours need at least one entry.');
            }
        });
    }
}
