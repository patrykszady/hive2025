<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Base extends Component
{
    public function render(): View
    {
        // Update the name of your view.
        return view('mails.base');
    }
}
