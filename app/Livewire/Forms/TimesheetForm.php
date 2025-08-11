<?php

namespace App\Livewire\Forms;

use App\Models\Project;
use App\Models\Timesheet;
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

    public function store()
    {
        $weekly_projects = $this->component->weekly_hours->groupBy('project.id');
        $hourly = $this->hourly;

        //update updated_at timestamp only if hourly_rate has changed
        $this->component->user->vendors()
            ->updateExistingPivot(auth()->user()->vendor->id, ['hourly_rate' => $hourly]);

        foreach ($weekly_projects as $project_id => $project_weekly_hours) {
            $project = Project::findOrFail($project_id);

            $hours = $project_weekly_hours->sum('hours');
            $timesheet = Timesheet::create([
                'date' => $this->component->week->startOfWeek()->format('Y-m-d'),
                'user_id' => $this->component->user->id,
                'vendor_id' => auth()->user()->vendor->id,
                'project_id' => $project_id,
                'hours' => $hours,
                'amount' => $hourly * $hours,
                'hourly' => $hourly,
                'created_by_user_id' => auth()->user()->id,
            ]);

            //get $weekly_hours->pluck('id') and associate $timesheet->id with each...
            foreach ($project_weekly_hours as $hour) {
                $hour->timesheet()->associate($timesheet)->save();
            }
        }

        return $timesheet;
    }
}
