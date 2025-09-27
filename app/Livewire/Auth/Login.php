<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

class Login extends Component
{
    public string $email = '';
    public string $password = '';
    public bool $remember = false; // Remember-me toggle
    
    protected $rules = [
        'email' => 'required|email',
        'password' => 'required',
    ];

    public function login(): void
    {
        $this->validate();

        $remember = (bool) $this->remember;

        if (Auth::attempt(['email' => $this->email, 'password' => $this->password], $remember)) {
            session()->regenerate();

            // Force a full browser navigation so the remember cookie (Set-Cookie header) is applied visibly.
            // Livewire 3 provides redirectIntended via component helper.
            $this->redirectIntended(default: route('dashboard'), navigate: true);
            return;
        }

        $this->addError('email', __('auth.failed'));
    }

    #[Title('Login')]
    public function render()
    {
        return view('livewire.auth.login')->layout('components.layouts.guest');
    }
}
