{{-- Card actions — included by BOTH the real card and its loading
     skeleton so the buttons are present from the first paint. --}}
<flux:button
    size="sm"
    icon="plus"
    wire:click="$dispatchTo('distributions.distribution-create', 'newDistribution')"
    >
    Add New
</flux:button>
