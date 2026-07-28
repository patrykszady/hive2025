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

    public function placeholder(array $params = [])
    {
        $project = $params['project'] ?? null;

        return view('livewire.projects.project-finances-placeholder', [
            'project' => $project,
            // The real card only shows Profit on Complete/Service Call projects
            // (project-finances.blade.php); painting it always makes the
            // skeleton a row taller than the card that replaces it.
            'showProfit' => $project instanceof \App\Models\Project
                && in_array($project->latestStatus?->title, ['Complete', 'Service Call'], true),
        ]);
    }
}
