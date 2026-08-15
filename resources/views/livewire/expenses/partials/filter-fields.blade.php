@props([
    'layout' => 'stacked', {{-- 'stacked' for mobile/modal, 'inline' for desktop --}}
])

@if($layout === 'inline')
    <div class="flex flex-col sm:flex-row items-end gap-4">
        <div class="flex-1 min-w-0 w-full">
            <flux:input wire:model.live.debounce.300ms="amount" label="Amount" icon="magnifying-glass" placeholder="Search Amount" />
        </div>

        <div class="flex-1 min-w-0 w-full">
            <flux:select wire:model.live="expense_vendor" label="Vendor" variant="listbox" searchable clearable placeholder="Choose Vendor...">
                <x-slot name="search">
                    <flux:select.search wire:model.live.debounce.300ms="vendorSearch" placeholder="Search vendors..." />
                </x-slot>
                <flux:select.option value="0">NO VENDOR</flux:select.option>
                <flux:select.option disabled>---------</flux:select.option>
                @foreach ($this->vendorOptions as $vendor)
                    <flux:select.option value="{{$vendor->id}}">{{ $vendor->name }}</flux:select.option>
                @endforeach
                @if ($this->hasMoreVendorOptions())
                    {{-- Scrolling this into view inside the open dropdown loads the next page. --}}
                    <div wire:intersect="loadMoreVendorOptions" class="px-3 py-2 text-xs text-zinc-400" wire:key="vendor-more-{{ $this->vendorLimit }}">
                        <span wire:loading.remove wire:target="loadMoreVendorOptions">Scroll for more…</span>
                        <span wire:loading wire:target="loadMoreVendorOptions">Loading…</span>
                    </div>
                @endif
            </flux:select>
        </div>

        <div class="flex-1 min-w-0 w-full">
            <flux:select wire:model.live="project_id" label="Project" variant="listbox" searchable clearable placeholder="Choose Project...">
                <x-slot name="search">
                    <flux:select.search wire:model.live.debounce.300ms="projectSearch" placeholder="Search projects..." />
                </x-slot>
                <flux:select.option value="NO_PROJECT">NO PROJECT</flux:select.option>
                <flux:select.option value="SPLIT">SPLIT</flux:select.option>
                <flux:select.option disabled>---------</flux:select.option>
                @foreach ($distributions as $distribution)
                    <flux:select.option value="D:{{$distribution->id}}">{{ $distribution->name }}</flux:select.option>
                @endforeach
                <flux:select.option disabled>---------</flux:select.option>
                @foreach ($this->projectOptions as $project)
                    <flux:select.option value="{{$project->id}}"><div>{{ $project->short_address }} <br> <i class="font-normal">{{$project->project_name}}</i></div></flux:select.option>
                @endforeach
                @if ($this->hasMoreProjectOptions())
                    <div wire:intersect="loadMoreProjectOptions" class="px-3 py-2 text-xs text-zinc-400" wire:key="project-more-{{ $this->projectLimit }}">
                        <span wire:loading.remove wire:target="loadMoreProjectOptions">Scroll for more…</span>
                        <span wire:loading wire:target="loadMoreProjectOptions">Loading…</span>
                    </div>
                @endif
            </flux:select>
        </div>

        <div class="flex-1 min-w-0 w-full">
            <flux:select variant="listbox" label="Status" multiple clearable placeholder="Choose status..." wire:model.live="expense_statuses">
                <flux:select.option value="Complete"><flux:badge size="md" inset="top bottom" color="green">Complete</flux:badge></flux:select.option>
                <flux:select.option value="No Transaction"><flux:badge size="md" inset="top bottom" color="yellow">No Transaction</flux:badge></flux:select.option>
                <flux:select.option value="No Project"><flux:badge size="md" inset="top bottom" color="red">No Project</flux:badge></flux:select.option>
                <flux:select.option value="Missing Info"><flux:badge size="md" inset="top bottom" color="amber">Missing Info</flux:badge></flux:select.option>
                @can('create', App\Models\Expense::class)
                    <flux:select.option value="Deleted"><flux:badge size="md" inset="top bottom" color="zinc">Deleted</flux:badge></flux:select.option>
                @endcan
            </flux:select>
        </div>

        <div class="flex-1 min-w-0 w-full">
            <flux:select wire:model.live="reimbursement_filter" label="Reimbursement" variant="listbox" clearable placeholder="All...">
                <flux:select.option value="Client">Client</flux:select.option>
                @foreach ($this->via_vendor_employees as $employee)
                    <flux:select.option value="{{ $employee->id }}">{{ $employee->first_name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="flex-1 min-w-0 w-full">
            <flux:date-picker
                wire:model.live="date_range"
                mode="range"
                with-presets
                presets="last30Days last3Months last6Months thisMonth lastMonth thisYear lastYear custom"
                clearable
                placeholder="All time"
                label="Date Range"
            />
        </div>
    </div>

    <div class="flex flex-col sm:flex-row items-end gap-4 mt-4">
        <div class="flex-1 min-w-0 w-full sm:max-w-xs">
            <flux:input wire:model.live.debounce.400ms="receipt_search" icon="magnifying-glass" placeholder="Search items, SKU, barcode..." clearable label="Receipt Items">
                <x-slot name="iconTrailing">
                    <flux:button size="sm" variant="subtle" icon="scan-barcode" class="-mr-1" x-on:click="$flux.modal('barcode-scanner').show()" tooltip="Scan barcode" />
                </x-slot>
            </flux:input>
            @if($upcProductName)
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    <flux:icon.scan-barcode variant="micro" class="inline size-3" /> UPC resolved to: <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $upcProductName }}</span>
                </p>
            @endif
        </div>
    </div>
@else
    <div class="flex flex-col gap-4">
        <div class="min-w-0 w-full">
            <flux:input wire:model.live.debounce.300ms="amount" label="Amount" icon="magnifying-glass" placeholder="Search Amount" />
        </div>
        @if($view !== 'vendors.show')
            <div class="min-w-0 w-full">
                <flux:select wire:model.live="expense_vendor" label="Vendor" variant="listbox" searchable clearable placeholder="Choose Vendor...">
                    <x-slot name="search">
                        <flux:select.search wire:model.live.debounce.300ms="vendorSearch" placeholder="Search vendors..." />
                    </x-slot>
                    @if($view !== 'projects.show')
                        <flux:select.option value="0">NO VENDOR</flux:select.option>
                        <flux:select.option disabled>---------</flux:select.option>
                    @endif
                    @foreach ($this->vendorOptions as $vendor)
                        <flux:select.option value="{{$vendor->id}}">{{ $vendor->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif
        @if($view === 'projects.show')
            {{-- Project is implicit on project page --}}
        @else
            <div class="min-w-0 w-full">
                <flux:select wire:model.live="project_id" label="Project" variant="listbox" searchable clearable placeholder="Choose Project...">
                    <x-slot name="search">
                        <flux:select.search wire:model.live.debounce.300ms="projectSearch" placeholder="Search projects..." />
                    </x-slot>
                    <flux:select.option value="NO_PROJECT">NO PROJECT</flux:select.option>
                    <flux:select.option value="SPLIT">SPLIT</flux:select.option>
                    <flux:select.option disabled>---------</flux:select.option>
                    @foreach ($distributions as $distribution)
                        <flux:select.option value="D:{{$distribution->id}}">{{ $distribution->name }}</flux:select.option>
                    @endforeach
                    <flux:select.option disabled>---------</flux:select.option>
                    @foreach ($this->projectOptions as $project)
                        <flux:select.option value="{{$project->id}}"><div>{{ $project->short_address }} <br> <i class="font-normal">{{$project->project_name}}</i></div></flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif
        <div class="min-w-0 w-full">
            <flux:select variant="listbox" label="Status" multiple clearable placeholder="Choose status..." wire:model.live="expense_statuses">
                <flux:select.option value="Complete"><flux:badge size="md" inset="top bottom" color="green">Complete</flux:badge></flux:select.option>
                <flux:select.option value="No Transaction"><flux:badge size="md" inset="top bottom" color="yellow">No Transaction</flux:badge></flux:select.option>
                <flux:select.option value="No Project"><flux:badge size="md" inset="top bottom" color="red">No Project</flux:badge></flux:select.option>
                <flux:select.option value="Missing Info"><flux:badge size="md" inset="top bottom" color="amber">Missing Info</flux:badge></flux:select.option>
                @can('create', App\Models\Expense::class)
                    <flux:select.option value="Deleted"><flux:badge size="md" inset="top bottom" color="zinc">Deleted</flux:badge></flux:select.option>
                @endcan
            </flux:select>
        </div>
        <div class="min-w-0 w-full">
            <flux:select wire:model.live="reimbursement_filter" label="Reimbursement" variant="listbox" clearable placeholder="All...">
                <flux:select.option value="Client">Client</flux:select.option>
                @foreach ($this->via_vendor_employees as $employee)
                    <flux:select.option value="{{ $employee->id }}">{{ $employee->first_name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <div class="min-w-0 w-full">
            <flux:date-picker
                wire:model.live="date_range"
                mode="range"
                with-presets
                presets="last30Days last3Months last6Months thisMonth lastMonth thisYear lastYear custom"
                clearable
                placeholder="All time"
                label="Date Range"
            />
        </div>
        <div class="min-w-0 w-full">
            <flux:input wire:model.live.debounce.400ms="receipt_search" icon="magnifying-glass" placeholder="Search items, SKU, barcode..." clearable label="Receipt Items">
                <x-slot name="iconTrailing">
                    <flux:button size="sm" variant="subtle" icon="scan-barcode" class="-mr-1" x-on:click="$flux.modal('barcode-scanner').show()" tooltip="Scan barcode" />
                </x-slot>
            </flux:input>
            @if($upcProductName)
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    <flux:icon.scan-barcode variant="micro" class="inline size-3" /> UPC resolved to: <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $upcProductName }}</span>
                </p>
            @endif
        </div>
        @can('create', App\Models\Expense::class)
            @if($amount && $view == NULL)
                <flux:button wire:click="$dispatchTo('expenses.expense-create', 'newExpense', { amount: {{$amount}}})">Add New Expense</flux:button>
            @endif
        @endcan
    </div>
@endif
