@props([
    'title' => null,
    'subheading' => null,
    'canEdit' => false,
    'expanded' => true,
    'details_text' => "Details"
])

<flux:card class="!px-5 py-2">
    {{-- HEADER - Always outside the accordion --}}
    <div class="flex justify-between items-center">
        <flux:heading size="lg" class="mb-0 truncate">{!! $title !!}</flux:heading>
        <div class="flex items-center">
            @if($canEdit)
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
        {{-- Separator only shown when details_text is not false AND we have details --}}
        @if($details_text !== false)
            <flux:separator class="my-2"/>
        @endif
        
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

    {{-- Footer with right-aligned content - only if footer slot exists --}}
    @if(isset($footer))
        <div class="flex justify-end mt-2 mb-0">
            {{ $footer }}
        </div>
    @endif
</flux:card>