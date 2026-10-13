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
    // The trigger is styled exactly like Flux's select button (flux:select.button):
    // same height per size, border, shadow, placeholder colour and chevron, so the
    // vendor picker sits in a row of flux:selects without looking different.
    $name = $attributes->whereStartsWith('wire:model')->first();
    $invalid = $name && isset($errors) && $errors->has($name);
    $triggerClasses = collect([
        'group/select-button cursor-default overflow-hidden flex items-center w-full shadow-xs',
        'bg-white dark:bg-white/10 dark:disabled:bg-white/[7%] disabled:shadow-none',
        match ($size) {
            'sm' => 'h-8 py-1.5 text-sm rounded-md ps-3 pe-2',
            'xs' => 'h-6 text-xs rounded-md ps-3 pe-2',
            default => 'h-10 py-2 text-base sm:text-sm rounded-lg px-3',
        },
        $invalid ? 'border border-red-500' : 'border border-zinc-200 border-b-zinc-300/80 dark:border-white/10',
    ])->implode(' ');
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
                <button type="button" class="{{ $triggerClasses }}" @if($invalid) data-invalid @endif data-flux-select-button>
                    <span class="min-w-0 flex-1 truncate text-start" x-text="label || @js($placeholder)"
                          x-bind:class="label ? 'text-zinc-700 dark:text-zinc-300' : 'text-zinc-400 dark:text-zinc-400'"></span>
                    <flux:icon.chevron-down variant="mini" class="ms-2 -me-1 shrink-0 text-zinc-400/75 group-hover/select-button:text-zinc-800 dark:text-white/60 dark:group-hover/select-button:text-white" />
                </button>
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
