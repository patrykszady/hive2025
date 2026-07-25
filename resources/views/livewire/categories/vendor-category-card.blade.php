<div>
    @if($embedded)
        {{-- Embedded mode: render content directly without card wrapper --}}
        <div class="space-y-4">
            @include('livewire.categories.partials.category-content')
        </div>
    @else
    <flux:card class="space-y-1 !px-5 !py-2">
        <div class="flex justify-between items-start gap-4">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 min-h-[2.25rem]">
                    <flux:heading size="lg" class="mb-0 truncate">
                        <a wire:navigate.hover href="{{ route('vendors.show', $vendor->id) }}" target="_blank" class="hover:underline">{{ $vendor->business_name }}</a>
                    </flux:heading>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                @if($vendor->sheets_type)
                    <flux:badge color="blue" size="sm">{{ $vendor->sheets_type }}</flux:badge>
                @endif
                @if($vendor->category)
                    <flux:badge color="green" size="sm">{{ $vendor->category->friendly_primary }}</flux:badge>
                @else
                    <flux:badge color="amber" size="sm">No Category</flux:badge>
                @endif
                <flux:badge color="gray" size="sm">{{ $this->expenseCount }}</flux:badge>
                <button
                    type="button"
                    wire:click="toggle"
                    class="inline-flex items-center justify-center size-7 rounded-md text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-transform cursor-pointer {{ $expanded ? 'rotate-180' : '' }}"
                >
                    <flux:icon.chevron-down class="size-4" />
                </button>
            </div>
        </div>

        @if($expanded)
            <flux:separator class="my-2" />
            <div class="space-y-4 py-2">
                @include('livewire.categories.partials.category-content')
            </div>
        @endif
    </flux:card>
    @endif
</div>
