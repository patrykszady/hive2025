@blaze

{{-- Filter card, single source of truth for index pages. ONE card, ONE copy of
     the fields: an Alpine toggle collapses the content below `sm` (accordion
     behavior) while `sm:block` keeps it always-open on desktop. The previous
     two-cards version (hidden mobile accordion + hidden desktop card) rendered
     every field twice — on /expenses that duplicated ~2.7MB of vendor/project
     select options and doubled the DOM Flux had to hydrate.

     Slots:
     - default slot  — filter fields ONCE, in responsive markup
                       (flex-col sm:flex-row sm:items-end gap-4 wrappers)
     - mobile/desktop— legacy API: layout-specific copies, still rendered as two
                       hidden-by-breakpoint blocks. Prefer the default slot.
     - actions       — header buttons (desktop; hidden on mobile when collapsed)
     - mobileActions — buttons beside the heading on mobile --}}
@props([
    'heading' => 'Filters',
])
<div {{ $attributes }}>
    @if(isset($mobile) || isset($desktop))
        {{-- Legacy two-copy variant --}}
        <flux:card class="!px-5 !py-2 sm:hidden">
            <flux:accordion transition>
                <flux:accordion.item>
                    <div class="flex items-center">
                        <div class="flex-1 min-w-0">
                            <flux:accordion.heading>
                                <flux:heading size="lg">{{ $heading }}</flux:heading>
                            </flux:accordion.heading>
                        </div>
                        @isset($mobileActions)
                            {{ $mobileActions }}
                        @endisset
                    </div>
                    <flux:accordion.content>
                        <div class="pb-2">
                            {{ $mobile ?? $slot }}
                        </div>
                    </flux:accordion.content>
                </flux:accordion.item>
            </flux:accordion>
        </flux:card>

        <x-island-card :heading="$heading" :separator="true" class="hidden sm:block">
            @isset($actions)
                <x-slot:actions>{{ $actions }}</x-slot:actions>
            @endisset
            {{ $desktop ?? $slot }}
        </x-island-card>
    @else
        {{-- Single-copy responsive variant --}}
        <x-island-card :heading="$heading" :separator="true" x-data="{ filtersOpen: false }">
            <x-slot:badge>
                {{-- Mobile-only expand toggle; on sm+ the content is always shown --}}
                <button type="button" class="sm:hidden flex items-center gap-1 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300"
                    x-on:click="filtersOpen = !filtersOpen">
                    <flux:icon.chevron-down variant="mini" class="transition-transform duration-200" x-bind:class="filtersOpen && 'rotate-180'" />
                </button>
            </x-slot:badge>
            @isset($actions)
                <x-slot:actions>{{ $actions }}</x-slot:actions>
            @endisset

            <div class="pb-2 sm:pb-0" x-bind:class="filtersOpen ? 'block' : 'hidden sm:block'">
                {{ $slot }}
            </div>
        </x-island-card>
    @endif
</div>
