<?php

namespace App\Livewire\Users;

use App\Models\User;
use Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;

class UserDetails extends Component
{
    use AuthorizesRequests;

    public User $user;

    protected $listeners = ['refreshComponent' => '$refresh'];

    #[Computed]
    public function passkeys(): Collection
    {
        return $this->user
            ->webAuthnCredentials()
            ->latest('created_at')
            ->get();
    }

    public function revokePasskey(string $credentialId): void
    {
        $this->authorize('update', $this->user);

        $credential = $this->user
            ->webAuthnCredentials()
            ->whereKey($credentialId)
            ->firstOrFail();

        if ($credential->disabled_at) {
            return;
        }

        $credential->disable();

        Flux::toast(
            text: 'Also remove the passkey from your device settings (e.g. Windows Settings → Accounts → Passkeys).',
            heading: 'Passkey revoked',
            variant: 'warning',
        );

        $this->dispatch('refreshComponent');
    }

    public function render(): View
    {
        return view('livewire.users.details');
    }
}
