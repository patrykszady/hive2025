@props([
    'title' => null,
    'subheading' => null,
    'canEdit' => false,
    'expanded' => true  // New prop with default value true
])

<flux:card class="!py-5">
    {{-- HEADER - Keep outside accordion --}}
    <div class="flex justify-between">
        <flux:heading size="lg" class="mb-0 truncate">{!! $title !!}</flux:heading>

        <div>
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

    <flux:separator class="my-2"/>

    {{-- DETAILS LIST wrapped in accordion --}}
    <flux:accordion transition>
        <flux:accordion.item :expanded="$expanded">  <!-- Use the prop here -->
            <flux:accordion.heading>
                Details
            </flux:accordion.heading>
            <flux:accordion.content>
                {{ $details }}
            </flux:accordion.content>
        </flux:accordion.item>
    </flux:accordion>

    {{-- Footer with right-aligned content --}}
    <div class="flex justify-end mt-4">
        {{ $footer ?? '' }}
    </div>
</flux:card>