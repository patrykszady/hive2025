<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class FormModal extends Component
{
    public function __construct(
        public string $name,
        public ?string $title = null,
        public ?string $formId = null,
        public ?string $maxHeight = '80vh',
    ) {
        $this->formId = $formId ?? $name . '_form';
    }

    public function render(): View|Closure|string
    {
        return view('components.form-modal');
    }
}
