{{-- Unmatched-transactions search — rendered both by the Transactions card and
     by its loading skeleton, so the input is usable while the table loads. --}}
<flux:input
    wire:model.live.debounce.300ms="transaction_search"
    placeholder="Search vendor (e.g. ZELLE)..."
    icon="magnifying-glass"
    clearable
/>
