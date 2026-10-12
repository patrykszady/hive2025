{{-- One vendor picker for the whole site (Patryk, 2026-10-11: "load the whole
     list"). The list comes once per page from /vendors/picker-options (cached,
     ETag) and is filtered in the browser; only the matches render, so it costs
     a few dozen rows, not 851 options. Flux's listbox was the alternative and
     weighs 1.3 KB per option (1.1 MB a picker), which is why these pickers
     used to search the server on every keystroke.

     Bind it like an input: <x-vendor-picker wire:model.live="expense_vendor" />
     - label, placeholder, description, clearable, size: as on a flux:select
     - extra:      static choices first, [['value' => '0', 'label' => 'NO VENDOR'], …]
     - details:    show "Name — City, ST" to tell same-name vendors apart
     - all:        every vendor, not only the ones visible to the company (Admins)
     - project-id: only vendors billed to that project --}}
@props([
    'label' => null,
    'placeholder' => 'Choose vendor...',
    'description' => null,
    'clearable' => false,
    'extra' => [],
    'details' => false,
    'all' => false,
    'projectId' => null,
    'size' => null,
])
@php
    $config = [
        'url' => route('vendors.picker-options', array_filter(['all' => $all ? 1 : null, 'project' => $projectId ?: null])),
        'extra' => array_values(array_map(fn ($option) => ['value' => (string) $option['value'], 'label' => (string) $option['label']], $extra)),
        'details' => (bool) $details,
    ];
@endphp
<div
    x-data="vendorPicker(@js($config))"
    x-modelable="value"
    {{ $attributes->whereStartsWith('wire:model') }}
    {{ $attributes->whereDoesntStartWith('wire:model')->class('min-w-0') }}
    data-vendor-picker
>
    <flux:field>
        @if($label)
            <flux:label>{{ $label }}</flux:label>
        @endif
        <div class="flex min-w-0 items-stretch gap-1">
            <flux:dropdown class="min-w-0 flex-1" position="bottom" align="start">
                <flux:button type="button" class="w-full justify-between!" icon-trailing="chevron-down" :size="$size">
                    <span class="truncate text-start" x-text="label || @js($placeholder)" x-bind:class="{ 'text-zinc-400 dark:text-zinc-400': !label }"></span>
                </flux:button>
                <flux:popover class="w-[min(28rem,calc(100vw-2rem))] p-2!" x-on:toggle="$event.newState === 'open' && opened()">
                    <flux:input
                        x-ref="search"
                        x-model="query"
                        x-on:input="active = 0"
                        x-on:keydown.down.prevent="move(1)"
                        x-on:keydown.up.prevent="move(-1)"
                        x-on:keydown.enter.prevent="pick()"
                        x-on:keydown.escape.prevent="close()"
                        size="sm"
                        icon="magnifying-glass"
                        placeholder="Search vendors..."
                        autocomplete="off"
                    />
                    <ul x-ref="list" class="mt-2 max-h-72 overflow-y-auto" role="listbox">
                        <template x-for="(option, i) in extraFiltered" :key="'extra-' + option.value">
                            <li role="option"
                                x-bind:data-active="active === i ? '' : null"
                                x-bind:aria-selected="String(value) === String(option.value)"
                                x-on:mouseenter="active = i"
                                x-on:click="choose(option.value)"
                                class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-zinc-800 data-active:bg-zinc-100 dark:text-white dark:data-active:bg-zinc-600">
                                <span class="flex w-5 shrink-0 justify-center"><flux:icon.check variant="mini" class="size-4" x-show="String(value) === String(option.value)" /></span>
                                <span class="truncate" x-text="option.label"></span>
                            </li>
                        </template>
                        <li x-show="extraFiltered.length && filtered.length" class="my-1 border-t border-zinc-200 dark:border-zinc-600"></li>
                        <template x-for="(vendor, j) in filtered" :key="vendor.id">
                            <li role="option"
                                x-bind:data-active="active === extraFiltered.length + j ? '' : null"
                                x-bind:aria-selected="String(value) === String(vendor.id)"
                                x-on:mouseenter="active = extraFiltered.length + j"
                                x-on:click="choose(vendor.id)"
                                class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-zinc-800 data-active:bg-zinc-100 dark:text-white dark:data-active:bg-zinc-600">
                                <span class="flex w-5 shrink-0 justify-center"><flux:icon.check variant="mini" class="size-4" x-show="String(value) === String(vendor.id)" /></span>
                                <span class="truncate" x-text="optionLabel(vendor)"></span>
                            </li>
                        </template>
                        <li x-show="loaded && !filtered.length && !extraFiltered.length" class="px-2 py-1.5 text-sm text-zinc-500 dark:text-zinc-400">No results found</li>
                        <li x-show="!loaded && !failed" class="px-2 py-1.5 text-sm text-zinc-500 dark:text-zinc-400">Loading vendors…</li>
                        <li x-show="failed" class="px-2 py-1.5 text-sm text-red-600 dark:text-red-400">
                            Could not load the vendor list.
                            <button type="button" class="underline" x-on:click="load()">Retry</button>
                        </li>
                    </ul>
                </flux:popover>
            </flux:dropdown>
            @if($clearable)
                <flux:button type="button" variant="subtle" icon="x-mark" x-show="hasValue" x-on:click="clear()" aria-label="Clear vendor" />
            @endif
        </div>
        @if($description)
            <flux:description>{{ $description }}</flux:description>
        @endif
    </flux:field>
</div>
