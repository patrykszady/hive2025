<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\HasToJsonMethod;
use App\Models\Project;
use App\Support\ProjectDocumentGenerator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectShow extends Component
{
    use AuthorizesRequests;
    use HasToJsonMethod;

    public Project $project;

    public $estimates = [];

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount()
    {
        // Only load critical relationships for initial render
        $this->project->load(['latestStatus', 'vendors']);
        
        $this->estimates = [];
    }

    public function print_reimbursements(): StreamedResponse
    {
        $this->authorize('view', $this->project);

        $document = ProjectDocumentGenerator::generateReimbursements($this->project);

        return response()->streamDownload(function () use ($document) {
            echo $document['binary'];
        }, $document['filename'], [
            'Content-Type' => 'application/pdf',
        ]);
    }

    #[Title('Project')]
    public function render()
    {
        $this->authorize('view', $this->project);
        return view('livewire.projects.show');
    }
}
