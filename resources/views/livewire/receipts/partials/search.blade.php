{{-- Receipt-pattern search — rendered both by the Email Receipts card and by
     its loading skeleton, so the input is usable while the table loads. --}}
<flux:input
    wire:model.live.debounce.300ms="search"
    placeholder="Search vendor, email, or subject..."
    icon="magnifying-glass"
    clearable
/>
