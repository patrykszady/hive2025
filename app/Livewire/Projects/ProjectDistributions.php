<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use Livewire\Component;

class ProjectDistributions extends Component
{
    public Project $project;

    public function mount(): void
    {
        $this->project->load('distributions');
    }

    public function render()
    {
        return view('livewire.projects.project-distributions');
    }

    public function placeholder()
    {
        return view('livewire.projects.project-distributions-placeholder');
    }
}
