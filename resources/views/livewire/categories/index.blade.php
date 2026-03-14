<div class="max-w-xl space-y-2 sm:px-6">
    <x-island-card heading="Vendor Categories" subheading="Manage retail vendor sheet types, default categories, and expense category assignments.">
        <div class="flex items-end gap-3 mt-2">
            <div class="flex-1">
                <flux:input size="sm" wire:model.live.debounce.300ms="search" placeholder="Search vendors..." icon="magnifying-glass" clearable />
            </div>
            <div class="w-28">
                <flux:select size="sm" wire:model.live="year">
                    <flux:select.option value="">All Years</flux:select.option>
                    @foreach($this->availableYears as $y)
                        <flux:select.option value="{{ $y }}">{{ $y }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>
    </x-island-card>

    @foreach($vendors as $vendor)
        <livewire:categories.vendor-category-card :vendor="$vendor" :year="$year" :key="$vendor->id . '-' . $year" />
    @endforeach

    @if($hasMore)
        <div wire:intersect="loadMore" class="py-4 text-center">
            <flux:icon.arrow-path class="size-5 text-zinc-400 animate-spin mx-auto" />
        </div>
    @endif

    <livewire:expenses.expense-create :key="'cat-expense-create'" />
</div>
