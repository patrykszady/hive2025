@props([
    'heading' => null,
    'href' => null,
    'subheading' => null,
    'separator' => false,
    'clickable' => false,
])

<div>
    <div class="flex justify-between items-start gap-4">
        {{-- Collapsible cards: the whole heading row toggles, not just the chevron. --}}
        <div @if($clickable) @click="open = !open" role="button" @endif @class(['min-w-0 flex-1', 'cursor-pointer select-none' => $clickable])>
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
        </div>
        <div class="flex items-center gap-2 flex-shrink-0 relative z-10">
            {{ $actions ?? '' }}
        </div>
    </div>

    @if($subheading || isset($subheading_actions))
        <div class="mt-0 flex w-full items-center justify-between gap-2" @if($clickable) @click="open = !open" role="button" @endif @class(['cursor-pointer' => $clickable])>
            @if($subheading)
                <flux:subheading class="mt-0 !leading-tight">
                    {{ $subheading }}
                </flux:subheading>
            @else
                <span></span>
            @endif

            @if(isset($subheading_actions))
                <div class="ml-auto flex items-center gap-1">
                    {{ $subheading_actions }}
                </div>
            @endif
        </div>
    @endif
</div>

@if($separator)
    <flux:separator variant="subtle" />
@endif
