<?php

namespace App\View\Components;

use Illuminate\View\Component;

class GuestLayout extends Component
{
    /**
     * $title and $description flow through to components.layouts.head's
     * <title> and meta description (Blade forwards a component's data
     * through its @include of that partial) — the mechanism
     * welcome/feature.blade.php already reaches for with `:title=`. Every
     * other guest page still renders the bare "Hive Contractors" fallback,
     * exactly as before, since both default to null.
     */
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
    ) {
    }

    /**
     * Get the view / contents that represents the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('components.layouts.guest');
    }
}
