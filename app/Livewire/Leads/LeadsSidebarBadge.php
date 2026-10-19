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
        // Bust so the re-render Livewire performs sees the fresh count.
        static::bustBadgeCache();
    }

    protected static function countCacheKey(?int $vendorId = null): string
    {
        return 'leads:badge:v'.($vendorId ?? auth()->user()?->vendor?->id ?? 0);
    }

    /**
     * Bust the count for a vendor (defaults to the current user's) — called
     * by the web's event listener above and by the Hive Contractors app's
     * LeadController after any write that can move a lead into or out of
     * "New", so the app's badge never reads a stale count either.
     */
    public static function bustBadgeCache(?int $vendorId = null): void
    {
        \Illuminate\Support\Facades\Cache::forget(static::countCacheKey($vendorId));
    }

    /**
     * Leads still awaiting a reply: latest status is "New" (or none yet).
     * Renders on every page (sidebar) — cached briefly, busted by the same
     * events that change it. Lead statuses also change via console commands
     * (crew:ingest-leads), so the short TTL is the safety net there.
     */
    public static function newLeadCount(): int
    {
        return (int) \Illuminate\Support\Facades\Cache::remember(
            static::countCacheKey(),
            60,
            fn () => Lead::whereLatestStatus('New')->count()
        );
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
