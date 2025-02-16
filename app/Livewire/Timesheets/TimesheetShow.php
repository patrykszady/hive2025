<?php

namespace App\Livewire\Timesheets;

use App\Models\Hour;
use App\Models\Timesheet;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

class TimesheetShow extends Component
{
    use AuthorizesRequests;

    public Timesheet $timesheet;

    #[Title('Timesheet')]
    public function render()
    {
        $this->authorize('view', $this->timesheet);

        $weekly_hours =
            Timesheet::with('check')
                ->orderBy('date', 'DESC')
                ->where('date', $this->timesheet->date->format('Y-m-d'))
                ->where('user_id', $this->timesheet->user_id)
                ->get();

        //Paid or Paid By or Pay/not Paid
        $timesheet_ids = $weekly_hours->pluck('id')->toArray();

        $daily_hours =
            Hour::orderBy('date', 'ASC')
                ->whereIn('timesheet_id', $timesheet_ids)
                ->get()
                ->groupBy('date');

        return view('livewire.timesheets.show', [
            'weekly_hours' => $weekly_hours,
            'daily_hours' => $daily_hours,
        ]);
    }
}
