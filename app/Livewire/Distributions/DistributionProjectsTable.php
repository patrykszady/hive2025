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
        // Perform query based on type.
        $projects = Project::with('distributions', 'last_complete_status')
            ->when($this->type === 'With', function ($query) {
                return $query->whereHas('distributions');
            })
            ->when($this->type === 'Without', function ($query) {
                return $query->whereDoesntHave('distributions');
            })
            ->status(['Complete', 'Service Call Complete'])
            ->sortByDesc('last_complete_status.start_date')
            ->paginate(5, pageName: 'projects-' . $this->type . '-distributions');

        return view('livewire.distributions.projects-table', [
            'projects' => $projects,
        ]);
    }
}
