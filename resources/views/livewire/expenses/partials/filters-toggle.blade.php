{{-- Filters control for the embedded Expenses card: a compact toggle that sits
     beside the card title (actions slot) and opens ONLY the filter fields —
     the card itself never collapses, so it behaves like every other shared
     index card. The fields render in the toolbar region below the header.
     Expects Alpine `filtersOpen` on the card's x-data. --}}
<button type="button" @click.stop="filtersOpen = !filtersOpen"
    class="flex items-center gap-1 text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200 cursor-pointer"
    aria-label="Toggle expense filters">
    Filters
    <flux:icon.chevron-down variant="mini" class="transition-transform duration-200" ::class="filtersOpen && 'rotate-180'" />
</button>
