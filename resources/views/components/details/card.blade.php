@props([
    'title' => null,
    'subheading' => null,
    'canEdit' => null,
    'expanded' => true,
    'details_text' => "Details",
    'accordion' => true
])

<flux:card class="!px-5 py-2">
    {{-- HEADER - Always outside the accordion --}}
    <div class="flex justify-between items-center min-h-[2.25rem]">
        <flux:heading size="lg" class="mb-0 truncate">{!! $title !!}</flux:heading>
        <div class="flex items-center min-h-[2.25rem]">
            {{-- Show buttons if canEdit is null or true --}}
            @if($canEdit === null || $canEdit)
                {{ $header_buttons ?? '' }}
            @endif
        </div>
    </div>

    {{-- SUBHEADING --}}
    @if($subheading)
        <flux:subheading>
            {{ $subheading }}
        </flux:subheading>
    @endif
    
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