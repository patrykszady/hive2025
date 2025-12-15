<?php

namespace App\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;

class BrowserTimezone extends Component
{
    #[On('browser-timezone-sync')]
    public function sync(string $timezone, string $date): void
    {
        $changed = session('browser.timezone') !== $timezone
            || session('browser.date') !== $date;

        session([
            'browser.timezone' => $timezone,
            'browser.date' => $date,
        ]);

        if (! $changed) {
            return;
        }

        $this->dispatch('refreshComponent');
    }

    public function render()
    {
        return view('livewire.browser-timezone');
    }
}
