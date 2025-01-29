<?php

namespace App\Livewire\Forms;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Rule;
use Livewire\Form;

class UserForm extends Form
{
    use AuthorizesRequests;

    public ?User $user;

    #[Rule('required')]
    public $first_name = null;

    #[Rule('required')]
    public $last_name = null;

    #[Rule('required')]
    public $email = null;

    #[Rule('nullable')]
    public $role = null;

    #[Rule('required_with:role')]
    public $hourly_rate = null;

    #[Rule('nullable')]
    public $via_vendor = null;

    #[Rule('nullable')]
    public $business_type = null;

    #[Rule('nullable')]
    public $business_name = null;
    // #[Rule('required')]
    // public $cell_phone = NULL;

    //         'user.cell_phone' => [
    //             'required',
    //             'digits:10',
    //             Rule::unique('users', 'cell_phone')->ignore($this->user->id),
    //         ],
    // #[Rule('required|digits:10')]
    // public $cell_phone = NULL;

    // #[Rule('required|min:3', as: 'project name')]
    // public $project_name = NULL;
    // protected function rules()
    // {
    //     return [
    //         'user.first_name' => 'required|min:2',
    //         'user.last_name' => 'required|min:2',
    //         'user.email' => [
    //             'required',
    //             'email',
    //             'min:6',
    //             Rule::unique('users', 'email')->ignore($this->user->id),
    //         ],
    //         'user.role' =>
    //             Rule::requiredIf(function(){
    //                 if($this->model['type'] == 'vendor'){
    //                     return true;
    //                 }else{
    //                     return false;
    //                 }
    //             }),
    //         'user.hourly_rate' =>
    //             Rule::requiredIf(function(){
    //                 if($this->model['id'] == 'NEW' && $this->model['type'] == 'vendor'){
    //                     return false;
    //                 }elseif($this->model['type'] == 'client'){
    //                     return false;
    //                 }elseif($this->model['id'] == auth()->user()->vendor->id && $this->model['type'] == 'vendor'){
    //                     return true;
    //                 }else{
    //                     return false;
    //                 }
    //             }),
    //     ];
    // }

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
}
