<?php

namespace App\Livewire\Forms;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;

use Livewire\Attributes\Validate;
use Livewire\Form;

class UserForm extends Form
{
    use AuthorizesRequests;

    public ?User $user;

    #[Validate('required|min:2')]
    public $first_name = null;

    #[Validate('required|min:2')]
    public $last_name = null;

    public $email = null;

    #[Validate('nullable')]
    public $role = null;

    #[Validate('required_with:role')]
    public $hourly_rate = null;

    #[Validate('nullable')]
    public $via_vendor = null;

    public function rules()
    {
        // Don't validate anything if the form hasn't been properly initialized
        if (!isset($this->user) && empty($this->email) && empty($this->first_name)) {
            return [];
        }

        return [
            'email' => [
                'required',
                'email',
                'min:4',
                // Rule::unique('users', 'email')->ignore($this->user?->id),
            ],
        ];

        // 'user.role' =>
        //     Rule::requiredIf(function(){
        //         if($this->model['type'] == 'vendor'){
        //             return true;
        //         }else{
        //             return false;
        //         }
        //     }),
        // 'user.hourly_rate' =>
        //     Rule::requiredIf(function(){
        //         if($this->model['id'] == 'NEW' && $this->model['type'] == 'vendor'){
        //             return false;
        //         }elseif($this->model['type'] == 'client'){
        //             return false;
        //         }elseif($this->model['id'] == auth()->user()->vendor->id && $this->model['type'] == 'vendor'){
        //             return true;
        //         }else{
        //             return false;
        //         }
        //     }),
    }

    // #[Rule('required')]
    // public $cell_phone = NULL;

    //         'user.cell_phone' => [
    //             'required',
    //             'digits:10',
    //             Rule::unique('users', 'cell_phone')->ignore($this->user->id),
    //         ],
    // #[Rule('required|digits:10')]
    // public $cell_phone = NULL;


    public function setUser(User $user)
    {
        $this->user = $user;

        $this->first_name = $user->first_name;
        $this->last_name = $user->last_name;
        $this->email = $user->email;
        // $this->cell_phone = $this->component->user_cell;
    }

    public function store()
    {
        $this->validate();

        $user = User::create([
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'cell_phone' => $this->component->user_cell,
        ]);

        return $user;
    }

    public function update()
    {
        $this->validate();

        $user = $this->user->update([
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            // 'cell_phone' => $this->component->user_cell,
        ]);

        return $user;
    }
}
