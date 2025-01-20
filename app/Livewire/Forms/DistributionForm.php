<?php

namespace App\Livewire\Forms;

use App\Models\Distribution;
use Livewire\Attributes\Rule;
// use Livewire\Attributes\Validate;
use Livewire\Form;

class DistributionForm extends Form
{
    public $users = [];

    #[Rule('required|integer')]
    public $user_id = null;

    #[Rule('required|min:3')]
    public $name = null;

    public function store()
    {
        $distribution = Distribution::create([
            'vendor_id' => auth()->user()->vendor->id,
            'name' => $this->name,
            'user_id' => $this->user_id,
        ]);

        return $distribution;
    }
}
