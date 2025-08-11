<?php

namespace App\Livewire\Timesheets;

use App\Models\Hour;
use App\Models\Timesheet;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use Flux;

use Livewire\Attributes\Title;
use Livewire\Component;

class TimesheetShow extends Component
{
    use AuthorizesRequests;

    public Timesheet $timesheet;
    public $weekly_hours = [];
    public $not_paid = false;
    public $daily_hours = [];

    public function mount()
    {
        $this->weekly_hours =
            Timesheet::with('check')
                ->orderBy('date', 'DESC')
                ->with('hours')
                ->where('date', $this->timesheet->date->format('Y-m-d'))
                ->where('user_id', $this->timesheet->user_id)
                ->get()
                ->each(function ($item, $key){
                    $item->status = $item->paid_by ? 'Paid By' : ($item->check_id ? 'Paid' : (auth()->user()->vendor_role == 'Admin' ? 'Pay' : 'Not Paid'));
                });

        $this->not_paid = $this->weekly_hours->pluck('status')->every(function ($value) {
            return $value === 'Pay';
        });

        //Paid or Paid By or Pay/not Paid
        $timesheet_ids = $this->weekly_hours->pluck('id')->toArray();

        $this->daily_hours =
            Hour::orderBy('date', 'ASC')
                ->whereIn('timesheet_id', $timesheet_ids)
                ->get()
                ->groupBy('date')
                ->toBase();
    }

    public function revert()
    {
        foreach($this->weekly_hours as $timesheet){
            foreach($timesheet->hours()->get() as $hour){
                $hour->timesheet_id = NULL;
                $hour->save();
            }
            $timesheet->delete();
        }

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Timesheet Rolled back.',
            // route / href / wire:click
            // text: money($expense->amount),
            text: '',
        );

        //last $hour from above
        // return redirect(route('timesheets.create', ['hour' => $hour]));
        $this->redirectRoute('timesheets.create', ['hour' => $hour]);
    }

    #[Title('Timesheet')]
    public function render()
    {
        $this->authorize('view', $this->timesheet);
        return view('livewire.timesheets.show');
    }
}
