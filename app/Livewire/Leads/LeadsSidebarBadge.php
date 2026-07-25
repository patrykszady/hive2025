<?php

namespace App\Livewire\Leads;

use App\Models\Lead;
use Livewire\Attributes\On;
use Livewire\Component;

class LeadsSidebarBadge extends Component
{
    #[On('lead-created')]
    #[On('lead-status-updated')]
    public function refreshBadge(): void
    {
        // Handling the event is enough — Livewire re-renders after.
    }

    /**
     * Leads still awaiting a reply: latest status is "New" (or none yet).
     */
    public static function newLeadCount(): int
    {
        return Lead::whereLatestStatus('New')->count();
    }

    public function render()
    {
        $count = 0;

        if (auth()->check() && auth()->user()->can('viewAny', Lead::class)) {
            $count = static::newLeadCount();
        }

        return view('livewire.leads.leads-sidebar-badge', [
            'count' => $count,
        ]);
    }
}
