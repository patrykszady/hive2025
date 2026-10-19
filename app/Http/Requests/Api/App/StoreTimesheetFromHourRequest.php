<?php

namespace App\Http\Requests\Api\App;

use App\Models\Hour;
use App\Services\Timesheets\TimesheetConfirmation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /api/app/v1/timesheets/create/{hour} — confirms a week's logged
 * Hours into a Timesheet, mirroring TimesheetForm's own rules: `hourly`
 * numeric|min:0 (TimesheetForm::$hourly), plus the cross-field guard that
 * rule alone can't express — the web's `amount` field (hours × hourly) must
 * be at least $0.01, which TimesheetForm::rules() enforces as its own
 * field but TimesheetCreate never collects independently (it's always
 * derived). Authorization: TimesheetPolicy::create() is an unimplemented
 * stub and is never actually consulted by the web's TimesheetCreate — see
 * TimesheetConfirmation's docblock — so this, too, allows any signed-in
 * team member once app.access has already run.
 */
class StoreTimesheetFromHourRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hourly' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('hourly')) {
                return;
            }

            $hour = Hour::find($this->route('hour'));
            if (! $hour) {
                return;
            }

            $weeklyHours = app(TimesheetConfirmation::class)->weeklyHours($hour->user, $hour->date);

            // Nothing left to confirm: the controller reports this as a 409
            // (an already-confirmed week), never as an hourly-rate problem.
            if ($weeklyHours->isEmpty()) {
                return;
            }

            $amount = $weeklyHours->sum('hours') * (float) $this->input('hourly');

            if ($amount < 0.01) {
                $validator->errors()->add('hourly', 'The hourly rate must result in a payable amount of at least $0.01.');
            }
        });
    }
}
