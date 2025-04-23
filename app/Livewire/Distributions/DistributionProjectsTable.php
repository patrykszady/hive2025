<?php

namespace App\Livewire\Distributions;

use Livewire\Component;
use Livewire\WithPagination;

use Livewire\Attributes\Lazy;
use App\Models\Project;

#[Lazy]
class DistributionProjectsTable extends Component
{
    use WithPagination;

    public $type; // Type of projects ("With" or "Without").

    public function mount($type)
    {
        $this->type = $type;
    }

    public function render()
    {
        $projects = Project::with(['distributions', 'statuses' => function ($query) {
            $query->where('title', 'Complete') // Fetch only statuses with the title "Complete"
                  ->orderBy('start_date', 'desc'); // Sort "Complete" statuses by start_date
        }])
        ->when($this->type === 'With', function ($query) {
            $query->whereHas('distributions'); // Include projects that have distributions
        })
        ->when($this->type === 'Without', function ($query) {
            $query->whereDoesntHave('distributions'); // Include projects that do not have distributions
        })
        ->whereHas('statuses', function ($query) {
            $query->where('title', 'Complete'); // Ensure projects have at least one "Complete" status
        })
        ->orderBy(function ($query) {
            $query->select('start_date')
                  ->from('project_status')
                  ->whereColumn('project_status.project_id', 'projects.id') // Match project ID
                  ->where('title', 'Complete') // Filter for "Complete" statuses
                  ->orderByDesc('start_date') // Order by the latest "Complete" start_date
                  ->limit(1); // Limit to the most recent start_date
        }, 'desc') // Sort projects by the latest "Complete" start_date
        ->paginate(5, ['*'], 'projects-' . $this->type . '-distributions'); // Paginate the results

        return view('livewire.distributions.projects-table', [
            'projects' => $projects,
        ]);
    }
}
