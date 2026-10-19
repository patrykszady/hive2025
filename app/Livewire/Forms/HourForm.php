<?php

namespace App\Livewire\Forms;

use App\Services\Hours\HourEntryWriter;
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
        return app(HourEntryWriter::class)->isProjectAccessible($projectId);
    }

    /** @see HourEntryWriter::store() — the actual write, shared with the app's HourController. */
    public function store()
    {
        $this->validate();

        app(HourEntryWriter::class)->store(auth()->user(), $this->component->selected_date, $this->projects);
    }

    /** @see HourEntryWriter::update() — the actual write, shared with the app's HourController. */
    public function update()
    {
        $this->validate();

        app(HourEntryWriter::class)->update(auth()->user(), $this->component->selected_date, $this->projects);
    }
}
