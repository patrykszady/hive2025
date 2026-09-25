<?php

namespace App\Livewire\Forms;

use App\Models\Hour;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Form;

class HourForm extends Form
{
    use AuthorizesRequests;

    public $projects = [];

    public function rules()
    {
        return [
            'projects.*.hours' => 'nullable|numeric|min:0|max:16',
        ];
    }

    public function setProjects($projects)
    {
        $this->projects = $projects;
    }

    /**
     * project_id rides in on a client-controlled array — check each one
     * against what this tenant can see (ProjectScope) rather than trusting
     * it, so hours can't be logged against a project outside the tenant.
     */
    protected function isProjectAccessible($projectId): bool
    {
        return $projectId && Project::whereKey($projectId)->exists();
    }

    public function store()
    {
        // $this->authorize('create', Expense::class);
        $this->validate();
        $projects_with_hours = collect($this->projects)
            ->where('hours', '!=', null)
            ->where('hours', '>', 0)
            ->filter(fn ($project) => $this->isProjectAccessible($project['id'] ?? null));

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
        $projects_with_hours = collect($this->projects)->where('hours', '!=', null);

        foreach ($projects_with_hours as $project) {
            //update existing hour
            if (isset($project['hour_id'])) {
                $hour = Hour::findOrFail($project['hour_id']);
                if ($project['hours'] == null || $project['hours'] == 0) {
                    $hour->delete();
                } elseif ($this->isProjectAccessible($project['id'] ?? null)) {
                    $hour->update([
                        'hours' => $project['hours'],
                        'project_id' => $project['id'],
                    ]);
                }
                //create new hour
            } else {
                if (isset($project['hours']) && $project['hours'] > 0 && $this->isProjectAccessible($project['id'] ?? null)) {
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
