<?php

namespace App\Livewire\Distributions;

use App\Models\Distribution;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

class DistributionsIndex extends Component
{
    use AuthorizesRequests;

    protected $listeners = ['refreshComponent' => '$refresh'];

    #[Title('Distributions')]
    public function render()
    {
        return view('livewire.distributions.index');
    }
}
