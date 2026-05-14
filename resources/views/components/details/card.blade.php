@props([
    'title' => null,
    'title_href' => null,
    'subheading' => null,
    'canEdit' => null,
    'expanded' => true,
    'details_text' => "Details",
    'accordion' => true,
    'nonLivewire' => false,
    'separator' => true,
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

<flux:card class="!px-5 !py-2" style="--flux-card-px: calc(var(--spacing) * 5);">
    {{-- HEADER - Uses shared island-card.header component --}}
    <x-island-card.header :heading="html_entity_decode((string) $title, ENT_QUOTES, 'UTF-8')" :href="$title_href" :subheading="$subheading" :clickable="$useInlineToggle">
        <x-slot:badge>
            {{ $title_extras ?? '' }}
        </x-slot:badge>
        <x-slot:actions>
            @if($canEdit === null || $canEdit)
                {{ $header_buttons ?? '' }}
            @endif
        </x-slot:actions>
        @if($useInlineToggle)
            <x-slot:subheading_actions>
                <button @click.stop="open = !open" class="p-1 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 cursor-pointer">
                    <svg x-bind:class="open && 'rotate-180'" class="size-4 transition-transform duration-200" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                    </svg>
                </button>
            </x-slot:subheading_actions>
        @endif
    </x-island-card.header>
    
    @if(isset($details))
        @if($separator)
            <flux:separator class="my-2"/>
        @endif
        
        @if($accordion === false)
            {{-- When accordion is false, skip the accordion and show content directly --}}
            <div class="py-2">
                {{ $details }}
            </div>
        @elseif($details_text === false)
            {{-- Inline toggle: chevron is in header actions --}}
            <div x-show="open" x-collapse @unless($expanded) x-cloak @endunless>
                {{ $details }}
            </div>
        @else
            {{-- Use Alpine toggle matching Project Timeline pattern --}}
            <div x-data="{ open: @js($expanded) }">
                <button type="button" @click="open = !open" class="flex w-full items-center justify-between py-2">
                    <div class="font-medium text-gray-700 dark:text-gray-300">{{ $details_text }}</div>
                    <flux:icon.chevron-down variant="mini" class="text-gray-400 transition-transform duration-200" ::class="open && 'rotate-180'" />
                </button>

                <div x-show="open" x-collapse @unless($expanded) x-cloak @endunless>
                    {{ $details }}
                </div>
            </div>
        @endif
    @endif

    {{-- Footer with right-aligned content - only if footer slot exists --}}
    @if(isset($footer))
        <div class="flex justify-end mt-2 mb-0">
            {{ $footer }}
        </div>
    @endif
</flux:card>

@if($useInlineToggle)
</div>
@endif