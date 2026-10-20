{{-- One vendor picker for the whole site, and it is Flux's own searchable
     select (flux:select variant="listbox" searchable): the same button,
     panel, search box and options as every other dropdown (Patryk,
     2026-10-13: "it should use the default flux as we use everywhere").

     Only the options are fed differently. The vendor list comes once per page
     from /vendors/picker-options (cached, ETag) and only the best matches, 60
     at most, are rendered as Flux options in the browser as you type. Flux
     renders an option as 1.3 KB of HTML, so all 851 vendors would cost 1.1 MB
     per picker (Patryk, 2026-10-11: "load the whole list"). Each option is
     built from a server-rendered Flux option, so its markup stays Flux's.

     Bind it like an input: <x-vendor-picker wire:model.live="expense_vendor" />
     - label, placeholder, description, clearable, size: as on a flux:select
     - extra:      static choices first, [['value' => '0', 'label' => 'NO VENDOR'], …]
     - details:    show "Name — City, ST" to tell same-name vendors apart
     - all:        every vendor, not only the ones visible to the company (Admins)
     - project-id: only vendors billed to that project
     - order:      "ytd" lists the most spent with over the last year first
                   (before any search) instead of A–Z --}}
@props([
    'label' => null,
    'placeholder' => 'Choose vendor...',
    'description' => null,
    'clearable' => false,
    'extra' => [],
    'details' => false,
    'all' => false,
    'projectId' => null,
    'order' => null,
    'size' => null,
])
@php
    $name = $attributes->whereStartsWith('wire:model')->first();
    $invalid = $name && isset($errors) && $errors->has($name);
    $extra = array_values(array_map(fn ($option) => ['value' => (string) $option['value'], 'label' => (string) $option['label']], $extra));
    // One Flux listbox option rendered with placeholders; the browser fills in
    // each vendor, so every option is exactly Flux's markup.
    $optionTemplate = once(fn () => \Illuminate\Support\Facades\Blade::render(
        '<flux:select.option variant="listbox" value="__VP_VALUE__" label="__VP_LABEL__">__VP_LABEL__</flux:select.option>'
    ));
    // The chosen vendor's option is printed by the server, so the button shows
    // its name on the first paint instead of waiting for the list.
    $boundValue = $name ? data_get(\Livewire\Livewire::current(), $name) : null;
    $selected = null;
    if ($boundValue !== null && $boundValue !== '') {
        $selected = collect($extra)->first(fn ($option) => $option['value'] === (string) $boundValue);
        if (! $selected && is_numeric($boundValue)) {
            $vendor = \App\Models\Vendor::withoutGlobalScopes()->select(['id', 'business_name', 'city', 'state'])->find((int) $boundValue);
            if ($vendor) {
                $selected = [
                    'value' => (string) $vendor->id,
                    'label' => $details && $vendor->city ? $vendor->name.' — '.$vendor->city.($vendor->state ? ', '.$vendor->state : '') : $vendor->name,
                ];
            }
        }
    }
    // Only settings that never change between renders: a changed x-data
    // attribute makes Alpine start the picker over (and forget the value)
    // when Livewire morphs the page after a pick.
    $config = [
        'url' => route('vendors.picker-options', array_filter(['all' => $all ? 1 : null, 'project' => $projectId ?: null, 'order' => $order ?: null])),
        'extra' => $extra,
        'details' => (bool) $details,
        'template' => $optionTemplate,
    ];
@endphp
<div
    x-data="vendorPicker(@js($config))"
    x-modelable="value"
    {{ $attributes->whereStartsWith('wire:model') }}
    {{ $attributes->whereDoesntStartWith('wire:model')->class('min-w-0') }}
    data-vendor-picker
>
    <flux:select
        variant="listbox"
        searchable
        :filter="false"
        x-model="value"
        x-on:open="open = true"
        x-on:close="open = false"
        :label="$label"
        :description="$description"
        :placeholder="$placeholder"
        :clearable="$clearable"
        :size="$size"
        :invalid="$invalid"
        :error:name="$name"
    >
        <x-slot name="search">
            <flux:select.search x-model="query" placeholder="Search vendors..." />
        </x-slot>

        {{-- No "No results found" row (Patryk, 2026-10-13). Flux's script
             expects the element, so it stays, never shown. --}}
        <x-slot name="empty">
            <flux:select.option.empty style="display: none" />
        </x-slot>

        {{-- Livewire leaves these options to the browser (wire:ignore). --}}
        <div class="contents" wire:ignore x-html="render()" data-vendor-picker-options>@if($selected){!! str_replace(['__VP_VALUE__', '__VP_LABEL__'], [e($selected['value']), e($selected['label'])], $optionTemplate) !!}@endif</div>
    </flux:select>
</div>
