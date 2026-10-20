<?php

namespace App\Livewire\Distributions;

use Livewire\Component;
use Livewire\WithPagination;

use Livewire\Attributes\Lazy;
use App\Services\Distributions\DistributionProjectAssignment;

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
        $projects = app(DistributionProjectAssignment::class)
            ->projectsQuery($this->type)
            ->paginate(5, ['*'], 'projects-' . $this->type . '-distributions');

        return view('livewire.distributions.projects-table', [
            'projects' => $projects,
        ]);
    }
}
