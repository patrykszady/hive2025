<?php

namespace App\Livewire\Distributions;

use App\Models\Distribution;
use Livewire\Component;

class DistributionsList extends Component
{
    protected $listeners = ['refreshComponent' => '$refresh', 'refreshForce'];

    public $distributions = [];

    public $registration = false;

    public function mount()
    {
        $this->distributions = Distribution::all();
    }

    public function refreshForce()
    {
        $this->mount();
        $this->render();
    }

    public function render()
    {
        $this->authorize('viewAny', Distribution::class);

        return view('livewire.distributions.list');
    }
}
