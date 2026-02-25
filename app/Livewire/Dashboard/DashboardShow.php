<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Traits\DetectsDeviceType;
use Illuminate\Contracts\View\View;

use Livewire\Attributes\Title;
use Livewire\Component;

class DashboardShow extends Component
{
    use DetectsDeviceType;
    public User $user;

    public bool $showPasskeyPrompt = false;

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount(): void
    {
        $this->user = auth()->user();
        $hasPasskeyForDevice = $this->user->webAuthnCredentials()
            ->whereNull('disabled_at')
            ->where('device_type', $this->currentDeviceType())
            ->exists();

        $this->showPasskeyPrompt = session()->pull('passkey_prompt', false) && ! $hasPasskeyForDevice;
    }

    #[Title('Hub')]
    public function render(): View
    {
        return view('livewire.dashboard.show');
    }
}
