<?php

namespace App\Livewire\Distributions;

use App\Models\Distribution;
use App\Services\Distributions\DistributionFigures;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

class DistributionsShow extends Component
{
    use AuthorizesRequests, WithPagination;

    public Distribution $distribution;

    public $year = null;

    public $date = [];

    public function mount()
    {
        //if $this->year = NULL = YTD
        if (is_null($this->year)) {
            $this->date['start'] = today()->subYear(1)->format('Y-m-d');
            $this->date['end'] = today()->format('Y-m-d');
        } else {
            dd('year isset/not null');
        }
    }

    public function render()
    {
        $this->authorize('view', $this->distribution);

        $figures = app(DistributionFigures::class)->detail(
            $this->distribution,
            $this->date['start'],
            $this->date['end'],
        );

        $this->distribution->earned = $figures['earned'];
        $this->distribution->paid = $figures['paid'];

        return view('livewire.distributions.show', [
            'distribution_vendors' => $figures['vendors'],
            'distribution_projects' => $figures['projects'],
        ]);
    }
}
