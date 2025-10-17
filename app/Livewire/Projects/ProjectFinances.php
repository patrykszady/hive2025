<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use App\Support\ProjectDocumentGenerator;

class ProjectFinances extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public $finances = [];

    protected $listeners = ['refresh' => 'refreshFinances', 'refreshComponent' => 'refreshFinances'];

    public function mount()
    {
        $this->finances = $this->project->finances;
    }

    public function refreshFinances()
    {
        // Refresh the project model to get fresh data
        $this->project = $this->project->fresh();
        $this->finances = $this->project->finances;
    }

    //Reimbursement print
    public function print_reimbursements()
    {
        $this->authorize('view', $this->project);

        $document = ProjectDocumentGenerator::generateReimbursements($this->project);

        return response()->streamDownload(function () use ($document) {
            echo $document['binary'];
        }, $document['filename'], [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function render()
    {
        return view('livewire.projects.project-finances');
    }
}
