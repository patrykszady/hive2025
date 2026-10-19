<?php

namespace App\Livewire\Forms;

use App\Services\Timesheets\TimesheetConfirmation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Form;

class TimesheetForm extends Form
{
    use AuthorizesRequests;

    #[Rule('required')]
    public $full_name = '';

    #[Rule('required|numeric|min:.25')]
    public $hours = '';

    #[Rule('required|numeric|min:0')]
    public $hourly = '';

    #[Rule('required|numeric|min:.01')]
    public $amount = '';

    public function setUser($user)
    {
        $this->full_name = $user->full_name;
        $this->hours = $user->hours;
        $this->hourly = $user->hourly;
        $this->amount = $user->amount;
    }

    /** @see TimesheetConfirmation::confirm() — the actual write, shared with the app's TimesheetCreateController. */
    public function store()
    {
        return app(TimesheetConfirmation::class)->confirm(
            auth()->user(),
            $this->component->user,
            $this->component->week,
            (float) $this->hourly,
        );
    }
}
