<?php

namespace App\Livewire\Users;

use App\Models\User;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use Livewire\Component;

class UserDetails extends Component
{
    use AuthorizesRequests;

    public User $user;

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function render()
    {
        return view('livewire.users.details');
    }
}
