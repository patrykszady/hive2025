<?php

namespace App\Livewire\Forms;

use App\Models\Distribution;
use App\Services\Distributions\DistributionFigures;
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

    public function store(): Distribution
    {
        return app(DistributionFigures::class)->create(auth()->user()->vendor, (int) $this->user_id, $this->name);
    }
}
