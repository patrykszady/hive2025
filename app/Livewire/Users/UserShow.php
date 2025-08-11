<?php

namespace App\Livewire\Users;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

class UserShow extends Component
{
    use AuthorizesRequests;

    public User $user;

    #[Title('User')]
    public function render()
    {
        $this->authorize('view', $this->user);
        return view('livewire.users.show');
    }
}
