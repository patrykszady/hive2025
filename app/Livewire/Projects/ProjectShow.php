<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

class ProjectShow extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public $estimates = [];

    protected $listeners = ['refreshComponent' => '$refresh'];

    //11/07/2024 took reimbursments to ProjectFiances

    public function mount()
    {
        //include deleted
        $this->estimates = $this->project->estimates()->orderBy('created_at', 'DESC')->get();
    }

    #[Title('Project')]
    public function render()
    {
        // $this->authorize('view', $this->project);

        return view('livewire.projects.show');
    }
}
