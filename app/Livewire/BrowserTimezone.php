<?php

namespace App\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;

class BrowserTimezone extends Component
{
    #[On('browser-timezone-sync')]
    public function sync(string $timezone, string $date): void
    {
        // What the page in front of the user was actually rendered with:
        // the session when set, otherwise the cookie timezone.js writes
        // BEFORE the first request (browser_timezone() reads that same
        // fallback). Comparing against the session alone meant every fresh
        // session fired a global refreshComponent — re-rendering every
        // listening component on the page for a value that already matched.
        $renderedTimezone = session('browser.timezone') ?? request()->cookie('browser_timezone');
        $renderedDate = session('browser.date') ?? request()->cookie('browser_date');

        $changed = $renderedTimezone !== $timezone || $renderedDate !== $date;

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
