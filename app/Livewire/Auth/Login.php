<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

class Login extends Component
{
    public string $email = '';
    public string $password = '';
    public bool $remember = false;
    
    // Step management: 'email' -> 'credentials'
    public string $step = 'email';
    public bool $hasPasskey = false;

    public function mount(): void
    {
        if (session('error')) {
            $this->email = '';
        }
    }

    public function checkEmail(): void
    {
        $this->validate(['email' => 'required|email']);

        $user = User::where('email', $this->email)->first();

        if ($user) {
            $this->hasPasskey = $user->webAuthnCredentials()->whereNull('disabled_at')->exists();
        } else {
            $this->hasPasskey = false;
        }

        $this->step = 'credentials';
    }

    public function goBack(): void
    {
        $this->step = 'email';
        $this->password = '';
        $this->hasPasskey = false;
        $this->resetErrorBag();
    }

    public function login(): void
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $remember = (bool) $this->remember;

        if (Auth::attempt(['email' => $this->email, 'password' => $this->password], $remember)) {
            session()->regenerate();

            $user = Auth::user();
            if ($user->webAuthnCredentials()->count() === 0) {
                $this->redirect(route('passkey.setup'), navigate: true);
                return;
            }

            $this->redirectIntended(default: route('dashboard'), navigate: true);
            return;
        }

        $this->addError('email', __('auth.failed'));
    }

    public function startOneTimeLogin(): void
    {
        $this->validate(['email' => 'required|email']);

        session()->put('one_time_login_email', $this->email);
        session()->put('one_time_login_force_send', true);

        $this->redirect(route('one-time-login'), navigate: true);
    }

    public function showPasswordLogin(): void
    {
        $this->hasPasskey = false;
    }

    #[Title('Login')]
    public function render()
    {
        return view('livewire.auth.login')->layout('components.layouts.guest');
    }
}
