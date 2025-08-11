<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;

use Livewire\Attributes\Title;
use Livewire\Component;

class Login extends Component
{
    public $email = '';
    public $password = '';
    // public $remember = false;

    protected $rules = [
        'email' => 'required|email',
        'password' => 'required',
    ];

    public function login()
    {
        $this->validate();

        //, $this->remember
        if (Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            session()->regenerate();
            return $this->redirect(route('dashboard'));
        }
        
        // Authentication failed
        $this->addError('email', __('auth.failed'));
    }

    #[Title('Login')]
    public function render()
    {
        return view('livewire.auth.login')->layout('components.layouts.guest');
    }
}
