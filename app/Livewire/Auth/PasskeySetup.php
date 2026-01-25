<?php

namespace App\Livewire\Auth;

use Livewire\Attributes\Title;
use Livewire\Component;

class PasskeySetup extends Component
{
    public bool $skipped = false;

    public function skip(): void
    {
        $this->redirectIntended(default: route('dashboard'), navigate: true);
    }

    #[Title('Set Up Passkey')]
    public function render()
    {
        return view('livewire.auth.passkey-setup')->layout('components.layouts.guest');
    }
}
