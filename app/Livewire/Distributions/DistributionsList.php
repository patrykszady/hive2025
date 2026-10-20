<?php

namespace App\Livewire\Distributions;

use App\Models\Distribution;
use App\Services\Distributions\DistributionFigures;
use Livewire\Component;

use Livewire\Attributes\Computed;

class DistributionsList extends Component
{
    /**
     * Column defs for the distributions table — the loading skeleton renders
     * from the same array as the real header row, so widths can't drift.
     *
     * @return array<int, array{label: string, width: string, skeleton?: string, skeletonWidth?: string}>
     */
    public static function columnDefs(): array
    {
        return [
            ['label' => 'Distribution', 'width' => 'w-[32%] min-w-0', 'skeletonWidth' => 'w-32'],
            ['label' => 'Balance', 'width' => 'text-right w-[17%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'YTD Earned', 'width' => 'text-right w-[17%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'YTD Paid', 'width' => 'text-right w-[17%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'YTD Balance', 'width' => 'text-right w-[17%]', 'skeletonWidth' => 'w-16'],
        ];
    }

    protected $listeners = ['refreshComponent' => '$refresh'];

    public $view = false;

    #[Computed]
    public function distributions()
    {
        return app(DistributionFigures::class)->ytdTable();
    }

    public function render()
    {
        $this->authorize('viewAny', Distribution::class);

        return view('livewire.distributions.list');
    }
}
