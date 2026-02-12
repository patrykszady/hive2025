@props([
    'heading' => null,
    'href' => null,
    'subheading' => null,
    'separator' => false,
])

<div class="flex justify-between items-start gap-4">
    <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2 min-h-[2.25rem]">
            <flux:heading size="lg" class="mb-0 truncate">
                @if($href)
                    <flux:link href="{{ $href }}" external variant="ghost" :accent="false" class="hover:underline">{{ html_entity_decode((string) $heading, ENT_QUOTES, 'UTF-8') }}</flux:link>
                @else
                    {{ $heading }}
                @endif
            </flux:heading>
            {{ $badge ?? '' }}
        </div>
        @if($subheading)
            <flux:subheading class="mt-0 !leading-tight">
                {{ $subheading }}
            </flux:subheading>
        @endif
    </div>
    <div class="flex items-center gap-2 flex-shrink-0 relative z-10">
        {{ $actions ?? '' }}
    </div>
</div>

@if($separator)
    <flux:separator variant="subtle" />
@endif
