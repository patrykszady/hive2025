<?php

namespace App\Livewire\Timesheets;

use App\Livewire\Forms\TimesheetForm;
use App\Models\Hour;
use Livewire\Attributes\Title;
use Livewire\Component;

class TimesheetCreate extends Component
{
    public TimesheetForm $form;

    public Hour $hour;

    public $user;

    public $week;

    public $weekly_hours;

    public function mount()
    {
        //7-6-2022 is this week already complete and have Timesheets?
        $this->week = $this->hour->date;
        $this->user = $this->hour->user;

        $this->weekly_hours = Hour::with('project')
            ->where('user_id', $this->user->id)
            ->whereNull('timesheet_id')
            ->whereBetween('date', [$this->week->startOfWeek()->format('Y-m-d'), $this->week->endOfWeek()->format('Y-m-d')])
            ->get();

        if ($this->weekly_hours->isEmpty()) {
            //7-6-2022 redirect with message ... week either has no hours or has already been confirmed
            return redirect()->route('timesheets.index');
        } else {
            $this->user->hours = $this->weekly_hours->sum('hours');
            $this->user->hourly = $this->user->vendors()->where('vendors.id', $this->user->vendor->id)->first()->pivot->hourly_rate;
            $this->user->amount = $this->getUserHoursAmountProperty();
            $this->user->user_role = $this->user->vendor->user_role;
            $this->user->logged_in = $this->user->id == auth()->user()->id ? true : false;
        }

        $this->form->setUser($this->user);
    }

    public function updatedFormHourly($value)
    {
        $this->user->hourly = $value;
        $this->user->hours = $this->weekly_hours->sum('hours');
        $this->getUserHoursAmountProperty();

        $this->form->setUser($this->user);
    }

    public function getUserHoursAmountProperty()
    {
        if (! empty($this->user->hourly)) {
            $total = $this->user->hours * $this->user->hourly;
        } else {
            $total = 0;
        }

        $this->user->amount = $total;

        return $total;
    }

    public function save()
    {
        // $this->authorize('update', $this->expense);
        $timesheet = $this->form->store();

        return redirect()->route('timesheets.show', $timesheet->id);
    }

    #[Title('Timesheets Create')]
    public function render()
    {
        $daily_hours = $this->weekly_hours->sortBy('date')->groupBy('date');

        $week_date = $this->week->startOfWeek()->toFormattedDateString();

        return view('livewire.timesheets.form', [
            'daily_hours' => $daily_hours,
            'week_date' => $week_date,
        ]);
    }
}
