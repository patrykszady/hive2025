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

    public function login_as_user()
    {
        $this->validate();

        $user = User::findOrFail($this->user_id);
        Auth::login($user);

        return redirect(route('vendor_selection'));
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
