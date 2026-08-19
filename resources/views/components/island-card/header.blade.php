@props([
    'heading' => null,
    'href' => null,
    {{-- Every card heading link in the app points at an internal route, so it
         navigates in place like x-table-link / x-details.row do. Pass
         :navigate="false" for a genuinely external URL (opens a new tab). --}}
    'navigate' => true,
    'subheading' => null,
    'separator' => false,
    'clickable' => false,
])

<div>
    <div class="flex justify-between items-center gap-4">
        {{-- Collapsible cards: the whole heading row toggles, not just the chevron. --}}
        <div @if($clickable) @click="open = !open" role="button" @endif @class(['min-w-0 flex-1', 'cursor-pointer select-none' => $clickable])>
            <div class="flex items-center gap-2 min-h-[2.25rem]">
                <flux:heading size="lg" class="mb-0 truncate">
                    {{-- Two whole tags, not one with @if inside it: Blade
                         directives don't compile inside a component tag — they
                         end up rendered as literal attributes. For the same
                         reason @click.stop rides on a plain <span> wrapper
                         rather than on <flux:link> itself.

                         That stop matters on a clickable (collapsible) card:
                         the link sits INSIDE the row's `open = !open` handler,
                         so without it, following the link also toggles the
                         accordion and the card collapses on the way out. --}}
                    @if($href)
                        <span @if($clickable) @click.stop @endif>
                            @if($navigate)
                                <flux:link href="{{ $href }}" wire:navigate.hover variant="ghost" :accent="false" class="no-underline hover:no-underline hover:text-indigo-600 dark:hover:text-indigo-400">{{ html_entity_decode((string) $heading, ENT_QUOTES, 'UTF-8') }}</flux:link>
                            @else
                                <flux:link href="{{ $href }}" external variant="ghost" :accent="false" class="no-underline hover:no-underline hover:text-indigo-600 dark:hover:text-indigo-400">{{ html_entity_decode((string) $heading, ENT_QUOTES, 'UTF-8') }}</flux:link>
                            @endif
                        </span>
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
