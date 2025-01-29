<?php

namespace App\Livewire\Forms;

use App\Models\Hour;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Form;

class HourForm extends Form
{
    use AuthorizesRequests;

    public $projects = [];

    public function rules()
    {
        return [
            'projects.*.hours' => 'nullable|numeric|min:.25|max:16',
        ];
    }

    public function setProjects($projects)
    {
        $this->projects = $projects;
    }

    public function store()
    {
        // $this->authorize('create', Expense::class);
        $this->validate();
        $projects_with_hours = collect($this->projects)->where('hours', '!=', null);

        foreach ($projects_with_hours as $project) {
            $this_hour = Hour::create([
                'date' => $this->component->selected_date,
                'hours' => $project['hours'],
                'project_id' => $project['id'],
                'user_id' => auth()->user()->id,
                'vendor_id' => auth()->user()->vendor->id,
                'created_by_user_id' => auth()->user()->id,
            ]);
        }
    }

    public function update()
    {
        // $this->authorize('create', Expense::class);
        $this->validate();
        $projects_with_hours = collect($this->projects);

        foreach ($projects_with_hours as $project) {
            //update existing hour
            if (isset($project['hour_id'])) {
                $hour = Hour::findOrFail($project['hour_id']);
                if ($project['hours'] == null) {
                    $hour->delete();
                } else {
                    $hour->update([
                        'hours' => $project['hours'],
                        'project_id' => $project['id'],
                    ]);
                }
                //create new hour
            } else {
                if (isset($project['hours'])) {
                    $hour = Hour::create([
                        'date' => $this->component->selected_date,
                        'hours' => $project['hours'],
                        'project_id' => $project['id'],
                        'user_id' => auth()->user()->id,
                        'vendor_id' => auth()->user()->vendor->id,
                        'created_by_user_id' => auth()->user()->id,
                    ]);
                }
            }
        }
    }
}
