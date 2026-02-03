<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use Illuminate\Contracts\View\View;

use Livewire\Attributes\Title;
use Livewire\Component;

class DashboardShow extends Component
{
    public User $user;

    public bool $showPasskeyPrompt = false;

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount(): void
    {
        $this->user = auth()->user();
        $hasPasskey = $this->user->webAuthnCredentials()->whereNull('disabled_at')->exists();

        $this->showPasskeyPrompt = session()->pull('passkey_prompt', false) && !$hasPasskey;
    }

    #[Title('Dashboard')]
    public function render(): View
    {
        return view('livewire.dashboard.show');
    }
}
