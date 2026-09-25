<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\HasToJsonMethod;
use App\Models\EmailTracking;
use App\Models\Project;
use App\Support\ProjectDocumentGenerator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
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

    /**
     * Memoized so the blade's visibility checks for the Expenses,
     * Email Tracking, and Distributions cards each run their `exists()`
     * query once per render instead of being re-evaluated inline.
     */
    #[Computed]
    public function hasExpenses(): bool
    {
        return $this->project->expenses()->exists();
    }

    #[Computed]
    public function hasEmailTracking(): bool
    {
        return EmailTracking::clientFacing()->forProjectAndItsLeads($this->project->id)->exists();
    }

    #[Computed]
    public function hasDistributions(): bool
    {
        return $this->project->distributions()->exists();
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
