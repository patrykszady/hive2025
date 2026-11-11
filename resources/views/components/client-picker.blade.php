{{-- Client picker: Flux's own searchable listbox (flux:select variant="listbox"
     searchable), bound like an input (wire:model on the component, Alpine
     x-modelable inside), fed in the browser from /clients/picker-options by
     the same Alpine component as the vendor picker (resources/js/vendor-
     picker.js, registered as clientPicker). The server prints one option —
     the chosen client — so the button shows its name on the first paint.

     Why (2026-11-06): the /projects filter rendered every client as a Flux
     option in both its layouts — 335 clients made 866 KB of a 1 MB page, and
     all of it travelled again on every status-card click.

     Props: label, placeholder, description, clearable, size as on a
     flux:select; extra = static rows [['value' => '', 'label' => '…']] shown
     before the clients. --}}
@props([
    'label' => null,
    'placeholder' => 'Choose client...',
    'description' => null,
    'clearable' => false,
    'extra' => [],
    'size' => null,
])
@php
    $name = $attributes->whereStartsWith('wire:model')->first();
    $invalid = $name && isset($errors) && $errors->has($name);
    $extra = array_values(array_map(fn ($option) => ['value' => (string) $option['value'], 'label' => (string) $option['label']], $extra));
    // One Flux listbox option rendered with placeholders; the browser fills in
    // each client, so every option is exactly Flux's markup.
    $optionTemplate = once(fn () => \Illuminate\Support\Facades\Blade::render(
        '<flux:select.option variant="listbox" value="__VP_VALUE__" label="__VP_LABEL__">__VP_LABEL__</flux:select.option>'
    ));
    $avatarTemplate = once(fn () => \Illuminate\Support\Facades\Blade::render(
        '<flux:select.option variant="listbox" value="__VP_VALUE__" label="__VP_LABEL__"><div class="flex items-center gap-2 whitespace-nowrap"><flux:avatar size="xs" initials="__VP_INITIALS__" color="red" />__VP_LABEL__</div></flux:select.option>'
    ));
    // The chosen client's option is printed by the server, so the button shows
    // its name before the list arrives.
    $boundValue = $name ? data_get(\Livewire\Livewire::current(), $name) : null;
    $selected = null;
    if ($boundValue !== null && $boundValue !== '') {
        $selected = collect($extra)->first(fn ($option) => $option['value'] === (string) $boundValue);
        if (! $selected && is_numeric($boundValue)) {
            $client = \App\Models\Client::query()->with('users')->find((int) $boundValue);
            if ($client) {
                $selected = ['value' => (string) $client->id, 'label' => (string) $client->name];
            }
        }
    }
    // Only settings that never change between renders: a changed x-data
    // attribute makes Alpine start the picker over (and forget the value)
    // when Livewire morphs the page after a pick.
    $config = [
        'url' => route('clients.picker-options'),
        'extra' => $extra,
        'details' => false,
        'template' => $optionTemplate,
        'avatarTemplate' => $avatarTemplate,
    ];
    $printedSelected = null;
    if ($selected) {
        $printedSelected = is_numeric($selected['value']) && ! collect($extra)->contains('value', $selected['value'])
            ? \Illuminate\Support\Facades\Blade::render(
                '<flux:select.option variant="listbox" :value="$value" :label="$label"><div class="flex items-center gap-2 whitespace-nowrap"><flux:avatar size="xs" :initials="$initials" color="auto" :color:seed="$value" />{{ $label }}</div></flux:select.option>',
                ['value' => $selected['value'], 'label' => $selected['label'], 'initials' => \App\Support\VendorInitials::for($selected['label'])],
            )
            : str_replace(['__VP_VALUE__', '__VP_LABEL__'], [e($selected['value']), e($selected['label'])], $optionTemplate);
    }
@endphp
<div
    x-data="clientPicker(@js($config))"
    x-modelable="value"
    {{ $attributes->whereStartsWith('wire:model') }}
    {{ $attributes->whereDoesntStartWith('wire:model')->class('min-w-0') }}
    data-client-picker
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
        options:class="w-max min-w-64 max-w-[min(28rem,calc(100vw-2rem))]"
    >
        <x-slot name="search">
            <flux:select.search x-model="query" placeholder="Search clients..." />
        </x-slot>

        {{-- Flux's script expects the empty row's element; the picker draws its own notes. --}}
        <x-slot name="empty">
            <flux:select.option.empty style="display: none" />
        </x-slot>

        {{-- Livewire leaves these options to the browser (wire:ignore). --}}
        <div class="contents" wire:ignore x-html="render()" data-client-picker-options>@if($printedSelected){!! $printedSelected !!}@endif</div>
    </flux:select>
</div>
