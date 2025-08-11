<?php

namespace App\Livewire\Distributions;

use App\Models\Distribution;
use Livewire\Component;

use Livewire\Attributes\Computed;

class DistributionsList extends Component
{
    protected $listeners = ['refreshComponent' => '$refresh'];

    public $view = false;

    #[Computed]
    public function distributions()
    {
        return Distribution::all();
    }

    public function render()
    {
        $this->authorize('viewAny', Distribution::class);

        return view('livewire.distributions.list');
    }
}
