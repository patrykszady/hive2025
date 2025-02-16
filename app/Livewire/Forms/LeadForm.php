<?php

namespace App\Livewire\Forms;

use Livewire\Attributes\Validate;
use Livewire\Form;

class LeadForm extends Form
{
    #[Validate('required|min:5')]
    public $title = '';
}
