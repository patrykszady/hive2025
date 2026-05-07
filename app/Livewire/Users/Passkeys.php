<?php

namespace App\Livewire\Users;

use App\Models\User;
use Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Passkeys extends Component
{
    use AuthorizesRequests;

    public User $user;

    /**
     * The credential currently being renamed.
     */
    #[Locked]
    public ?string $renamingId = null;

    public string $newAlias = '';

    protected $listeners = [
        'passkey-registered' => 'onRegistered',
    ];

    public function mount(User $user): void
    {
        $this->authorize('view', $user);

        $this->user = $user;
    }

    /**
     * Whether the authenticated user can register passkeys for this profile.
     *
     * Only the user themselves can register a passkey, since WebAuthn
     * must run in the user's own browser.
     */
    #[Computed]
    public function canRegister(): bool
    {
        return auth()->check() && (int) auth()->id() === (int) $this->user->id;
    }

    /**
     * Whether the authenticated user can manage (rename / disable / delete) passkeys.
     */
    #[Computed]
    public function canManage(): bool
    {
        return auth()->check() && (int) auth()->id() === (int) $this->user->id;
    }

    #[Computed]
    public function passkeys(): Collection
    {
        return $this->user
            ->webAuthnCredentials()
            ->latest('created_at')
            ->get();
    }

    public function onRegistered(): void
    {
        unset($this->passkeys);

        Flux::toast(
            text: 'Your passkey is ready to use the next time you sign in.',
            heading: 'Passkey added',
            variant: 'success',
        );
    }

    public function startRename(string $credentialId): void
    {
        abort_unless($this->canManage, 403);

        $credential = $this->findCredential($credentialId);

        $this->renamingId = $credential->id;
        $this->newAlias = (string) ($credential->alias ?? $credential->device_name ?? '');
    }

    public function cancelRename(): void
    {
        $this->renamingId = null;
        $this->newAlias = '';
        $this->resetErrorBag('newAlias');
    }

    public function saveRename(): void
    {
        abort_unless($this->canManage, 403);

        if ($this->renamingId === null) {
            return;
        }

        $this->validate([
            'newAlias' => ['required', 'string', 'min:1', 'max:64'],
        ]);

        $credential = $this->findCredential($this->renamingId);

        $credential->alias = trim($this->newAlias);
        $credential->save();

        $this->renamingId = null;
        $this->newAlias = '';

        unset($this->passkeys);

        Flux::toast(text: 'Your passkey alias has been updated.', heading: 'Passkey renamed', variant: 'success');
    }

    public function disablePasskey(string $credentialId): void
    {
        abort_unless($this->canManage, 403);

        $credential = $this->findCredential($credentialId);

        if ($credential->isDisabled()) {
            return;
        }

        $credential->disable();

        unset($this->passkeys);

        Flux::toast(
            text: 'Also remove the passkey from your device settings (e.g. Windows Settings → Accounts → Passkeys, iCloud Keychain, or your password manager).',
            heading: 'Passkey disabled',
            variant: 'warning',
        );
    }

    public function enablePasskey(string $credentialId): void
    {
        abort_unless($this->canManage, 403);

        $credential = $this->findCredential($credentialId);

        if ($credential->isEnabled()) {
            return;
        }

        $credential->enable();

        unset($this->passkeys);

        Flux::toast(text: 'You can now sign in with this passkey again.', heading: 'Passkey re-enabled', variant: 'success');
    }

    public function deletePasskey(string $credentialId): void
    {
        abort_unless($this->canManage, 403);

        $credential = $this->findCredential($credentialId);

        $credential->delete();

        unset($this->passkeys);

        Flux::toast(
            text: 'Remember to remove the passkey from your device settings as well.',
            heading: 'Passkey deleted',
            variant: 'warning',
        );
    }

    private function findCredential(string $credentialId)
    {
        return $this->user
            ->webAuthnCredentials()
            ->whereKey($credentialId)
            ->firstOrFail();
    }

    public function render(): View
    {
        return view('livewire.users.passkeys');
    }
}
