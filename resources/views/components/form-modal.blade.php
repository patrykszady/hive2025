@props([
    'name',
    'title' => null,
    'formId' => null,
    'maxHeight' => '80vh',
    'inline' => false,
])

@php
    $formId = $formId ?? $name . '_form';
    // Cards v2 (config/ui.php): the modal's header and footer are Flux 2.21's
    // card parts, standalone — the same chrome as every card on the page.
    $cardsV2 = \App\Support\Ui\CardsV2::active();
@endphp

@if($inline)
    <div {{ $attributes->merge(['class' => 'rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 h-full min-h-0 overflow-hidden']) }}>
        <div class="flex flex-col h-full min-h-0">
            {{-- Header --}}
            @if($cardsV2 && ! isset($header))
                <div class="p-4 pb-2 shrink-0">
                    <flux:card.header size="sm">
                        @if($title)<flux:card.heading>{{ $title }}</flux:card.heading>@endif
                        @if(isset($subtitle))<flux:card.subheading>{{ $subtitle }}</flux:card.subheading>@endif
                        @if(isset($headerActions))<flux:card.actions>{{ $headerActions }}</flux:card.actions>@endif
                    </flux:card.header>
                    <flux:separator variant="subtle" class="mt-2" />
                </div>
            @else
            <div class="p-4 pb-2 space-y-2 shrink-0">
                @if(isset($header))
                    {{ $header }}
                @else
                    <div class="flex items-center justify-between gap-2 min-w-0">
                        <div class="min-w-0">
                            @if($title)
                                <flux:heading size="lg">{{ $title }}</flux:heading>
                            @endif
                            @if(isset($subtitle))
                                {{ $subtitle }}
                            @endif
                        </div>
                        @if(isset($headerActions))
                            {{ $headerActions }}
                        @endif
                    </div>
                @endif
                <flux:separator variant="subtle" />
            </div>
            @endif

            {{-- Content --}}
            <div class="px-4 pb-4 flex-1 min-h-0 overflow-y-auto">
                {{ $slot }}
            </div>

            {{-- Footer --}}
            @if(isset($footer))
                @if($cardsV2)
                    <div class="border-t border-zinc-100 dark:border-zinc-800 p-4 bg-white dark:bg-zinc-900 shrink-0">
                        <flux:card.footer size="sm"><flux:card.actions>{{ $footer }}</flux:card.actions></flux:card.footer>
                    </div>
                @else
                <div class="border-t border-zinc-100 dark:border-zinc-800 p-4 bg-white dark:bg-zinc-900 shrink-0">
                    <div class="flex items-center gap-2">
                        {{ $footer }}
                    </div>
                </div>
                @endif
            @endif
        </div>
    </div>
@else
{{-- Wrapper div ensures Livewire's wire:id / wire:snapshot attach to a stable
     element instead of Flux's <ui-modal>, whose @blaze(fold:true) directive
     hoists/caches markup and strips per-render snapshots. --}}
<div>
<flux:modal :name="$name" {{ $attributes->merge(['class' => 'min-w-96 !p-0']) }}>
    <div class="flex flex-col" style="max-height: {{ $maxHeight }}">
        {{-- Header --}}
        @if($cardsV2 && ! isset($header))
            <div class="p-6 pb-2 shrink-0">
                <flux:card.header size="sm">
                    @if($title)<flux:card.heading>{{ $title }}</flux:card.heading>@endif
                    @if(isset($subtitle))<flux:card.subheading>{{ $subtitle }}</flux:card.subheading>@endif
                    @if(isset($headerActions))<flux:card.actions>{{ $headerActions }}</flux:card.actions>@endif
                </flux:card.header>
                <flux:separator variant="subtle" class="mt-2" />
            </div>
        @else
        <div class="p-6 pb-2 space-y-2 shrink-0">
            @if(isset($header))
                {{ $header }}
            @else
                <div class="flex items-center justify-between gap-2 min-w-0">
                    <div class="min-w-0">
                        @if($title)
                            <flux:heading size="lg">{{ $title }}</flux:heading>
                        @endif
                        @if(isset($subtitle))
                            {{ $subtitle }}
                        @endif
                    </div>
                    @if(isset($headerActions))
                        {{ $headerActions }}
                    @endif
                </div>
            @endif
            <flux:separator variant="subtle" />
        </div>
        @endif

        {{-- Scrollable Content --}}
        <div class="flex-1 overflow-y-auto px-6 pb-4">
            {{ $slot }}
        </div>

        {{-- Footer --}}
        @if(isset($footer))
            <div class="relative border-t border-zinc-100 dark:border-zinc-800 p-6 pt-4 bg-white dark:bg-zinc-950 shrink-0">
                <div class="pointer-events-none absolute inset-x-0 -top-8 h-8 bg-gradient-to-t from-white dark:from-zinc-950 to-transparent"></div>
                @if($cardsV2)
                    <flux:card.footer size="sm" class="relative"><flux:card.actions>{{ $footer }}</flux:card.actions></flux:card.footer>
                @else
                <div class="relative flex items-center gap-2">
                    {{ $footer }}
                </div>
                @endif
            </div>
        @endif
    </div>
</flux:modal>
</div>
@endif