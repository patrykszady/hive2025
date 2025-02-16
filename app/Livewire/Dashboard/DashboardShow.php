<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use Livewire\Attributes\Title;
use Livewire\Component;

class DashboardShow extends Component
{
    public User $user;

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount()
    {
        $this->user = auth()->user();
    }

    #[Title('Dashboard')]
    public function render()
    {
        return view('livewire.dashboard.show');
    }
}
