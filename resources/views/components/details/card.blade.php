@props([
    'title' => null,
    'title_href' => null,
    'subheading' => null,
    'canEdit' => null,
    'expanded' => true,
    // false = single header row: the chevron toggle sits next to the title
    // instead of a separate "Details" label row. Pass a string for a labeled
    // sub-row (e.g. "Receipt Items", "Split Details").
    'details_text' => false,
    'accordion' => true,
    'nonLivewire' => false,
    'separator' => true,
    // Fade in on insertion — true only for skeletons (see x-island-card).
    'enter' => false,
])

@php
    // When nonLivewire is true, force static rendering behaviors
    if ($nonLivewire) {
        $accordion = false;
        $expanded = true;
        $canEdit = false;
    }

    $useInlineToggle = isset($details) && $details_text === false && $accordion;
@endphp

@if($useInlineToggle)
<div x-data="{ open: @js($expanded) }">
@endif

{{-- wire:transition — cards that appear/disappear from server renders fade smoothly --}}
<flux:card wire:transition.opacity.duration.200ms class="!px-5 !py-2" style="--flux-card-px: calc(var(--spacing) * 5);">
    {{-- HEADER - Uses shared island-card.header component --}}
    <x-island-card.header :heading="html_entity_decode((string) $title, ENT_QUOTES, 'UTF-8')" :href="$title_href" :subheading="$subheading" :clickable="$useInlineToggle">
        <x-slot:badge>
            {{ $title_extras ?? '' }}
        </x-slot:badge>
        <x-slot:actions>
            @if($canEdit === null || $canEdit)
                {{ $header_buttons ?? '' }}
            @endif
            @if($useInlineToggle)
                {{-- Single-row header: accordion chevron lives next to the title.
                     type=button: inside a <form> a bare button would submit it. --}}
                <x-card-collapse-toggle />
            @endif
        </x-slot:actions>
    </x-island-card.header>

    {{-- Always-visible body content (e.g. a check image) shown above the collapsible details --}}
    {{ $slot }}
    
    @if(isset($details))
        {{-- Without a footer, .card-flush-bottom (app.css) pulls the last
             row of a list/table flush to the card's bottom edge, full-bleed
             hovers intact, clipped to the rounded corners. Collapsed cards
             keep their padding (the region's height is 0). --}}
        @if($accordion === false)
            {{-- When accordion is false, skip the accordion and show content directly --}}
            @if($separator)
                <flux:separator variant="subtle" class="my-2"/>
            @endif
            <div class="pt-2 {{ isset($footer) ? 'pb-2' : 'card-flush-bottom' }}">
                {{ $details }}
            </div>
        @elseif($details_text === false)
            {{-- Inline toggle: chevron is in header actions. The separator
                 rides inside the collapse — a closed card shows no stray line. --}}
            <div x-show="open" x-collapse @unless($expanded) x-cloak @endunless>
                @if($separator)
                    <flux:separator variant="subtle" class="my-2"/>
                @endif
                <div class="{{ isset($footer) ? '' : 'card-flush-bottom' }}">
                    {{ $details }}
                </div>
            </div>
        @else
            @if($separator)
                <flux:separator variant="subtle" class="my-2"/>
            @endif
            {{-- Use Alpine toggle matching Project Timeline pattern --}}
            <div x-data="{ open: @js($expanded) }">
                <button type="button" @click="open = !open" class="flex w-full items-center justify-between py-2">
                    <div class="font-medium text-gray-700 dark:text-gray-300">{{ $details_text }}</div>
                    <flux:icon.chevron-down class="size-4 text-gray-400 transition-transform duration-200" ::class="open && 'rotate-180'" />
                </button>

                <div x-show="open" x-collapse @unless($expanded) x-cloak @endunless>
                    {{ $details }}
                </div>
            </div>
        @endif
    @endif

    {{-- Footer with right-aligned content - only if footer slot exists.
         No own margins: footers often just host teleported modals (visually
         empty), and a spacer would bloat the collapsed card. Empty content
         collapses to zero height; visible footer content spaces itself. --}}
    @if(isset($footer))
        <div class="flex justify-end">
            {{ $footer }}
        </div>
    @endif
</flux:card>

@if($useInlineToggle)
</div>
@endif