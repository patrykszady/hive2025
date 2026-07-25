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

    /**
     * How many skeleton rows the loading placeholder should paint — the card's
     * page size, so the skeleton is the same height as the table that replaces
     * it (no jump on load). Callers that can cheaply COUNT the real rows pass
     * the smaller of the two.
     */
    public static function placeholderRows(): int
    {
        return 5;
    }

    /**
     * Column defs — the real header row and the loading skeleton render from
     * this one array, so widths can't drift.
     *
     * @return array<int, array{label: string, width: string, skeleton?: string, skeletonWidth?: string}>
     */
    public static function columnDefs(string $type = ''): array
    {
        return [
            ['label' => trim('Projects '.$type), 'width' => 'w-[50%] min-w-0', 'skeletonWidth' => 'w-40'],
            ['label' => 'Profit', 'width' => 'w-[25%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'Completed', 'width' => 'w-[25%]', 'skeletonWidth' => 'w-20'],
        ];
    }

    public $type; // Type of projects ("With" or "Without").

    /** Shared index-table skeleton while this lazy component loads. */
    public function placeholder(array $params = [])
    {
        return view('livewire.distributions.projects-table-placeholder', [
            'type' => $params['type'] ?? '',
        ]);
    }

    public function mount($type)
    {
        $this->type = $type;
    }

    public function render()
    {
        $projects = Project::with(['distributions', 'statuses' => function ($query) {
            $query->where('status_code', 7) // Fetch only "Complete" statuses
                  ->orderBy('start_date', 'asc'); // Sort to get first "Complete" date
        }])
        ->when($this->type === 'With', function ($query) {
            $query->whereHas('distributions'); // Include projects that have distributions
        })
        ->when($this->type === 'Without', function ($query) {
            $query->whereDoesntHave('distributions'); // Include projects that do not have distributions
        })
        ->whereHas('statuses', function ($query) {
            $query->where('status_code', 7); // Ensure projects have at least one "Complete" status
        })
        ->orderBy(function ($query) {
            $query->select('start_date')
                  ->from('project_status')
                  ->whereColumn('project_status.project_id', 'projects.id') // Match project ID
                  ->where('status_code', 7) // Filter for "Complete" statuses
                  ->orderBy('start_date', 'asc') // Order by the first "Complete" start_date
                  ->limit(1); // Limit to the first start_date
        }, 'desc') // Sort projects by first "Complete" date, most recent first
        ->paginate(5, ['*'], 'projects-' . $this->type . '-distributions'); // Paginate the results

        return view('livewire.distributions.projects-table', [
            'projects' => $projects,
        ]);
    }
}
