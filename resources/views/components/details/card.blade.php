@props([
    'title' => null,
    'title_href' => null,
    'subheading' => null,
    'canEdit' => null,
    'expanded' => true,
    'details_text' => "Details",
    'accordion' => true,
    'nonLivewire' => false,
])

@php
    // When nonLivewire is true, force static rendering behaviors
    if ($nonLivewire) {
        $accordion = false;
        $expanded = true;
        $canEdit = false;
    }
@endphp

<flux:card class="!px-5 !py-2">
    {{-- HEADER - Uses shared island-card.header component --}}
    <x-island-card.header :heading="html_entity_decode((string) $title, ENT_QUOTES, 'UTF-8')" :href="$title_href" :subheading="$subheading">
        <x-slot:badge>
            {{ $title_extras ?? '' }}
        </x-slot:badge>
        <x-slot:actions>
            @if($canEdit === null || $canEdit)
                {{ $header_buttons ?? '' }}
            @endif
        </x-slot:actions>
    </x-island-card.header>
    
    @if(isset($details))
        {{-- Separator always shown when we have details --}}
        <flux:separator class="my-2"/>
        
        @if($accordion === false)
            {{-- When accordion is false, skip the accordion and show content directly --}}
            <div class="py-2">
                {{ $details }}
            </div>
        @else
            {{-- Use accordion when accordion is true --}}
            <flux:accordion transition>
                <flux:accordion.item :expanded="$expanded">
                    @if($details_text === false)
                        {{-- For sheets.show: Empty heading to place toggle next to title --}}
                        <div class="flex justify-end items-center -mt-6">
                            <flux:accordion.heading>
                            </flux:accordion.heading>
                        </div>
                    @else
                        {{-- For checks.show: Heading with Details text --}}
                        <flux:accordion.heading>
                            <div class="flex justify-between items-center">
                                <div class="font-medium text-gray-700 dark:text-gray-300">{{ $details_text }}</div>
                            </div>
                        </flux:accordion.heading>
                    @endif
                    
                    <flux:accordion.content>
                        {{ $details }}
                    </flux:accordion.content>
                </flux:accordion.item>
            </flux:accordion>
        @endif
    @endif

    {{-- Footer with right-aligned content - only if footer slot exists --}}
    @if(isset($footer))
        <div class="flex justify-end mt-2 mb-0">
            {{ $footer }}
        </div>
    @endif
</flux:card>