<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

class Login extends Component
{
    public string $identifier = '';
    public string $email = '';
    public string $password = '';
    public bool $remember = false;
    public bool $can_continue = false;
    
    // Step management: 'email' -> 'credentials'
    public string $step = 'email';
    public bool $hasPasskey = false;
    public bool $hasPassword = false;

    public function mount(): void
    {
        if (session('error')) {
            $this->email = '';
            $this->identifier = '';
            $this->can_continue = false;
        }
    }

    public function updatedIdentifier(): void
    {
        $this->can_continue = $this->isIdentifierValid($this->identifier);
    }

    public function checkEmail(): void
    {
        $this->validate(['identifier' => 'required|string']);

        $identifier = trim($this->identifier);

        // Check if it's a valid email or phone format
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
        $digits = preg_replace('/\D/', '', $identifier);
        $isPhone = strlen($digits) === 10;

        if (! $isEmail && ! $isPhone) {
            // Determine which format they're likely trying to enter
            if (str_contains($identifier, '@')) {
                $this->addError('identifier', 'Please enter a valid email address.');
            } elseif (strlen($digits) > 0) {
                $this->addError('identifier', 'Please enter a valid 10-digit phone number.');
            } else {
                $this->addError('identifier', 'Please enter a valid email or phone number.');
            }
            return;
        }

        $user = $this->resolveUserFromIdentifier($this->identifier);

        if (! $user) {
            if ($isEmail) {
                $this->addError('identifier', 'No account found with this email address.');
            } else {
                $this->addError('identifier', 'No account found with this phone number.');
            }
            return;
        }

        if (!($user->registration['registered'] ?? false)) {
            $cell = $user->cell_phone ?: $this->identifier;
            session()->flash('registration_notice', 'unregistered');
            session()->flash('registration_prefill_cell', $cell);
            $this->redirect(route('registration', ['step' => 'phone']), navigate: true);
            return;
        }

        $this->email = (string) $user->email;
        $this->hasPasskey = $user->webAuthnCredentials()->whereNull('disabled_at')->exists()
            && $this->canUsePasskeysForCurrentRequest();
        $this->hasPassword = filled($user->password);
        $this->step = 'credentials';
    }

    public function goBack(): void
    {
        $this->step = 'email';
        $this->password = '';
        $this->hasPasskey = false;
        $this->hasPassword = false;
        $this->resetErrorBag();
    }

    public function login(): void
    {
        $this->validate([
            'password' => 'required',
        ]);

        if ($this->email === '') {
            $this->addError('identifier', __('auth.failed'));
            return;
        }

        $remember = (bool) $this->remember;

        if (Auth::attempt(['email' => $this->email, 'password' => $this->password], $remember)) {
            session()->regenerate();

            $user = Auth::user();

            // Client-only users go to their client home
            if ($user->is_client_user) {
                $client = $user->primary_client;

                if ($client) {
                    $this->redirect(route('clients.show', $client), navigate: true);
                    return;
                }

                $this->redirect(route('clients.index'), navigate: true);
                return;
            }

            if (!$user->webAuthnCredentials()->whereNull('disabled_at')->exists()) {
                $this->redirect(route('passkey.setup'), navigate: true);
                return;
            }
            
            $this->redirectIntended(default: route('dashboard'), navigate: true);
            return;
        }

        $this->addError('identifier', __('auth.failed'));
    }

    public function startOneTimeLogin(): void
    {
        $this->validate(['identifier' => 'required|string']);

        $user = $this->resolveUserFromIdentifier($this->identifier);

        if (! $user) {
            $this->addError('identifier', __('auth.failed'));
            return;
        }

        $this->email = (string) $user->email;
        session()->put('one_time_login_email', $this->email);
        session()->put('one_time_login_force_send', true);

        $this->redirect(route('one-time-login'), navigate: true);
    }

    public function showPasswordLogin(): void
    {
        $this->hasPasskey = false;
    }

    protected function resolveUserFromIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return User::where('email', $identifier)->first();
        }

        $digits = preg_replace('/\D/', '', $identifier);

        if (strlen($digits) !== 10) {
            return null;
        }

        $formatted = sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));

        return User::query()
            ->where('cell_phone', $identifier)
            ->orWhere('cell_phone', $digits)
            ->orWhere('cell_phone', $formatted)
            ->first();
    }

    protected function isIdentifierValid(string $identifier): bool
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return false;
        }

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        $digits = preg_replace('/\D/', '', $identifier);

        return strlen($digits) === 10;
    }

    protected function canUsePasskeysForCurrentRequest(): bool
    {
        $request = request();

        if (! $request->isSecure() && ! $request->isFromTrustedProxy()) {
            return false;
        }

        $rpId = trim((string) config('webauthn.id', ''));
        if ($rpId === '') {
            return true;
        }

        $host = trim((string) $request->getHost());
        if ($host === '') {
            return false;
        }

        return $host === $rpId || Str::endsWith($host, '.'.$rpId);
    }

    #[Title('Login')]
    public function render()
    {
        return view('livewire.auth.login')->layout('components.layouts.guest');
    }
}
