<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Traits\DetectsDeviceType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Login extends Component
{
    use DetectsDeviceType;
    public string $identifier = '';
    public string $email = '';
    public string $password = '';
    public bool $remember = false;
    public bool $can_continue = false;
    
    // Step management: 'email' -> 'credentials'
    public string $step = 'email';
    public bool $hasPasskey = false;

    /** Why the passkey step was abandoned — shown above the fallback options. */
    public ?string $passkeyNotice = null;
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
        $this->hasPasskey = $user->webAuthnCredentials()
                ->whereNull('disabled_at')
                ->where('device_type', $this->currentDeviceType())
                // A passkey belongs to the relying party that minted it: one
                // created on hive.contractors is invisible to a page served
                // from localhost, and vice versa. Without this the button was
                // offered against credentials the browser can never be shown,
                // and the ceremony could only end in "credentials request was
                // not completed" — which is exactly how local dev looked.
                ->where('rp_id', (string) config('webauthn.relying_party.id'))
                ->exists()
            && $this->canUsePasskeysForCurrentRequest();
        $this->hasPassword = filled($user->password);
        $this->passkeyNotice = null;
        $this->step = 'credentials';
    }

    public function goBack(): void
    {
        $this->step = 'email';
        $this->password = '';
        $this->hasPasskey = false;
        $this->passkeyNotice = null;
        $this->hasPassword = false;
        $this->resetErrorBag();
    }

    public function login(): void
    {
        $this->validate([
            'password' => 'required',
        ]);

        if ($this->email === '') {
            $this->addError('password', __('auth.failed'));
            return;
        }

        $remember = (bool) $this->remember;

        if (Auth::attempt(['email' => $this->email, 'password' => $this->password], $remember)) {
            session()->regenerate();

            $user = Auth::user();

            // Client-only users go to their client home (unless they have an intended URL)
            if ($user->is_client_user) {
                $client = $user->primary_client;
                $default = $client ? route('clients.show', $client) : route('clients.index');
                $this->redirectIntended(default: $default, navigate: true);
                return;
            }

            $hasPasskeyForDevice = $user->webAuthnCredentials()
                ->whereNull('disabled_at')
                ->where('device_type', $this->currentDeviceType())
                ->exists();

            if (! $hasPasskeyForDevice) {
                $this->redirect(route('passkey.setup'), navigate: true);
                return;
            }

            $this->redirectIntended(default: route('dashboard'), navigate: true);
            return;
        }

        $this->addError('password', __('auth.failed'));
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

    /**
     * Leave the passkey step for the fallback options. Called by the page
     * when the ceremony cannot proceed: `no-passkey` is NotAllowedError —
     * the browser found no matching credential on this device (Windows
     * Hello / Touch ID passkeys never leave the device that made them) or
     * the prompt was dismissed. Silently swapping to the fallback read as
     * "passkey login is broken", so the reason is now said out loud.
     */
    public function showPasswordLogin(?string $reason = null): void
    {
        $this->hasPasskey = false;
        $this->passkeyNotice = match ($reason) {
            'no-passkey' => 'No passkey for this device was found, or the prompt was closed. A passkey made on another computer or phone only works there.',
            'unsupported' => 'This browser can\'t use passkeys here.',
            default => null,
        };
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
        $host = trim((string) $request->getHost());

        // WebAuthn needs a secure context. Browsers grant that to loopback
        // over plain http as well (localhost, 127.0.0.1, ::1), so demanding
        // TLS here only blocked local development — the browser would have
        // been perfectly happy. Note that an IP literal still fails below:
        // Chrome refuses an IP address as a passkey domain
        // ("SecurityError: This is an invalid domain"), so locally the
        // relying party must be `localhost` and the page opened there.
        if (! $request->isSecure() && ! $request->isFromTrustedProxy() && ! $this->isLoopbackHost($host)) {
            return false;
        }

        $rpId = trim((string) config('webauthn.relying_party.id', ''));
        if ($rpId === '') {
            return true;
        }

        if ($host === '') {
            return false;
        }

        return $host === $rpId || Str::endsWith($host, '.'.$rpId);
    }

    protected function isLoopbackHost(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * The same page on `localhost`, when passkeys would work there but not
     * here: you're on 127.0.0.1 (which no browser accepts as a passkey
     * domain) and the relying party is localhost. Null everywhere else.
     */
    #[Computed]
    public function passkeyLocalhostUrl(): ?string
    {
        $request = request();
        $host = strtolower(trim((string) $request->getHost(), '[]'));

        if (! in_array($host, ['127.0.0.1', '::1'], true)) {
            return null;
        }

        if (trim((string) config('webauthn.relying_party.id', '')) !== 'localhost') {
            return null;
        }

        // Build from the login route, not fullUrl(): inside a Livewire update
        // the current URL is the /livewire-<hash>/update endpoint, which sent
        // the reader to a JSON endpoint instead of the sign-in page.
        $port = $request->getPort();
        $port = in_array($port, [80, 443, null], true) ? '' : ':'.$port;

        return $request->getScheme().'://localhost'.$port.route('login', absolute: false);
    }

    #[Title('Login')]
    public function render()
    {
        return view('livewire.auth.login')->layout('components.layouts.guest');
    }
}
