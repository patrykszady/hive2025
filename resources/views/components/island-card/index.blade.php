@props([
    'heading' => null,
    'href' => null,
    'subheading' => null,
    'separator' => false,
])

<flux:card {{ $attributes->class('space-y-2 !px-5 !py-2') }}>
    <x-island-card.header
        :heading="$heading"
        :href="$href"
        :subheading="$subheading"
        :separator="$separator"
    >
        @if(isset($badge))
            <x-slot:badge>{{ $badge }}</x-slot:badge>
        @endif
        @if(isset($actions))
            <x-slot:actions>{{ $actions }}</x-slot:actions>
        @endif
    </x-island-card.header>

    {{ $slot }}
</flux:card>
