@props([
    'title',
    'content' => null,
    'secondary_content' => null,  // New prop for notes
    'href' => null,
    'copyable' => false,
    'align' => 'left',
    'indent' => 0,
    'truncate' => true,
    'noCloak' => false,
    'navigate' => false,
])

@php
    $isRight = $align === 'right' || $attributes->get('right-align', false);
    $indentClass = ((int)($attributes->get('indent-level', $indent)) > 0) ? 'pl-' . ((int)($attributes->get('indent-level', $indent)) * 4) : '';
    $shouldTruncate = !$attributes->has('no-truncate') && $truncate;
    // Allow opting out of cloaking via prop or attribute
    $useCloak = !$noCloak && !$attributes->has('no-cloak');
@endphp
<div
    class="details-row relative flex flex-col sm:grid sm:grid-cols-4 gap-1 items-start py-2 sm:py-3 [&:not(:last-child)]:border-b [&:not(:last-child)]:border-zinc-800/15 dark:[&:not(:last-child)]:border-white/20"
    @if($useCloak) x-data="{}" x-cloak @endif
>
    @if($isRight)
        {{-- RIGHT-ALIGNED: title is clickable --}}
        <div class="hidden sm:block sm:col-span-1"></div>
        <flux:subheading class="{{ $shouldTruncate ? 'truncate' : 'whitespace-normal break-words' }} sm:col-span-2 text-right pr-3 font-normal {{ $indentClass }}">
            @if($href)
                @if($navigate)
                    <flux:link href="{{ $href }}" variant="ghost" :accent="false" class="font-normal no-underline hover:no-underline hover:text-indigo-600 dark:hover:text-indigo-400" wire:navigate.hover>
                        {{ $title }}
                    </flux:link>
                @else
                    <flux:link href="{{ $href }}" target="_blank" variant="ghost" :accent="false" class="font-normal no-underline hover:no-underline hover:text-indigo-600 dark:hover:text-indigo-400">
                        {{ $title }}
                    </flux:link>
                @endif
            @else
                {{ $title }}
            @endif
        </flux:subheading>
    @else
        {{-- LEFT-ALIGNED: content is clickable --}}
        <flux:subheading class="{{ $shouldTruncate ? 'truncate' : 'whitespace-normal break-words' }} {{ $indentClass }}">
            {{ $title }}
        </flux:subheading>
    @endif

    {{-- CONTENT --}}
    <div class="w-full {{ $isRight ? 'sm:col-span-1 text-right' : 'sm:col-span-3' }} {{ $copyable ? 'sm:pr-8' : '' }}">
        <div class="space-y-1">
            <flux:heading class="!my-0 font-bold {{ $shouldTruncate ? 'truncate' : 'whitespace-normal break-words' }}">
                <span class="{{ $isRight ? 'float-right' : '' }}">
                    @if($href && !$isRight)
                        @if($navigate)
                            <flux:link href="{{ $href }}" variant="ghost" :accent="false" class="font-bold no-underline hover:no-underline hover:text-indigo-600 dark:hover:text-indigo-400" wire:navigate.hover>
                                {!! $content ?? $slot !!}
                            </flux:link>
                        @else
                            <flux:link href="{{ $href }}" target="_blank" variant="ghost" :accent="false" class="font-bold no-underline hover:no-underline hover:text-indigo-600 dark:hover:text-indigo-400">
                                {!! $content ?? $slot !!}
                            </flux:link>
                        @endif
                    @else
                        {!! $content ?? $slot !!}
                    @endif
                </span>
            </flux:heading>

            {{-- SECONDARY CONTENT (NOTES) --}}
            @if($secondary_content)
                <div class="text-indigo-800 text-sm italic">
                    {!! is_string($secondary_content) ? $secondary_content : '' !!}
                </div>
            @endif
        </div>
    </div>

    {{-- Copy Button --}}
    @if($copyable && ($content || trim((string) $slot) !== ''))
        <div 
            class="absolute right-0 top-2" 
            x-data="{
                copied: false,
                rawContent: '{{ addslashes(html_entity_decode(preg_replace('/<[^>]*>/', ' ', $content ?? (string) $slot ?? ''))) }}',
                copyText() {
                    navigator.clipboard.writeText(this.rawContent)
                        .then(() => { this.copied = true; setTimeout(() => { this.copied = false }, 1500); })
                        .catch(() => {
                            $flux.toast({
                                text: 'Failed to copy {{ $title }} to clipboard.',
                                variant: 'danger',
                                timeout: 2000,
                                position: 'top right'
                            });
                        });
                }
            }"
        >
            <div x-show="!copied">
                <flux:button size="xs" icon="clipboard-document" icon:variant="outline" tooltip="Copy {{ $title }}" x-on:click="copyText()" />
            </div>
            <div x-show="copied">
                <flux:button size="xs" icon="check" variant="primary" color="green" disabled />
            </div>
        </div>
    @endif
</div>