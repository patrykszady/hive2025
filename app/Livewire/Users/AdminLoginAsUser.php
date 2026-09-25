<?php

namespace App\Livewire\Users;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AdminLoginAsUser extends Component
{
    use AuthorizesRequests;

    public $user_id = null;

    public $view_text = [
        'card_title' => 'Login As Another User',
        'button_text' => 'Login As User',
        'form_submit' => 'login_as_user',
    ];

    protected function rules()
    {
        return [
            'user_id' => 'required',
        ];
    }

    public function mount(): void
    {
        $this->authorize('admin_login_as_user', User::class);
    }

    public function login_as_user()
    {
        // render()'s gate alone isn't enough: this is a separate callable
        // action, and Auth::login must never run before it is re-checked.
        $this->authorize('admin_login_as_user', User::class);

        $this->validate();

        $user = User::findOrFail($this->user_id);
        Auth::login($user);

        session()->put('is_admin_login_as', true);

        return redirect(route('account_selection'));
    }

    public function render()
    {
        $this->authorize('admin_login_as_user', User::class);

        $users = User::withoutGlobalScopes()->orderBy('first_name', 'ASC')->whereNotIn('id', [1])->get();

        return view('livewire.users.admin-login-as-user', [
            'users' => $users,
        ]);
    }
}
