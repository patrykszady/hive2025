@props([
    'title',
    'content' => null,
    'href' => null,
    'copyable' => false,
    'bold' => false,
    'indent' => false,
    'indent-level' => 1,
    'right-align' => false,
    'no-truncate' => false
])

<div class="relative flex flex-col sm:grid sm:grid-cols-4 gap-1 items-start py-2 sm:py-3 [&:not(:last-child)]:border-b [&:not(:last-child)]:border-zinc-800/15 dark:[&:not(:last-child)]:border-white/20">
    @if($attributes->get('right-align', ${'right-align'}))
        {{-- Empty column for spacing when right-aligned --}}
        <div class="hidden sm:block sm:col-span-1"></div>
        
        {{-- Right-aligned title in column 3 - MAKE TITLE CLICKABLE INSTEAD OF AMOUNT --}}
        <flux:subheading class="truncate sm:col-span-2 text-right pr-3 {{ $indent ? 'pl-' . ($attributes->get('indent-level', ${'indent-level'}) * 4) : '' }}">
            @if($href)
                <flux:link 
                    href="{{ $href }}" 
                    variant="ghost"
                    :accent="false"
                    class="text-right font-normal"
                >
                    {{ $title }}
                </flux:link>
            @else
                {{ $title }}
            @endif
        </flux:subheading>
    @else
        {{-- Standard left-aligned title --}}
        <flux:subheading class="{{ $attributes->has('no-truncate') || ${'no-truncate'} ? '' : 'truncate' }} {{ $indent ? 'pl-' . ($attributes->get('indent-level', ${'indent-level'}) * 4) : '' }}">
            @if($href)
                <flux:link 
                    href="{{ $href }}" 
                    variant="ghost"
                    :accent="false"
                    class="font-normal"
                >
                    {{ $title }}
                </flux:link>
            @else
                {{ $title }}
            @endif
        </flux:subheading>
    @endif
    
    {{-- Content - Modified to handle HTML content --}}
    <div class="w-full {{ $attributes->get('right-align', ${'right-align'}) ? 'sm:col-span-1' : 'sm:col-span-3' }} {{ $copyable ? 'sm:pr-8' : '' }} {{ $attributes->get('right-align', ${'right-align'}) ? 'text-right' : '' }}">
        <flux:heading class="!my-0 {{ $bold ? 'font-bold' : '' }} {{ $attributes->has('no-truncate') || ${'no-truncate'} ? '' : 'truncate' }}">
            <span class="{{ $attributes->get('right-align', ${'right-align'}) ? 'float-right' : '' }}">
                @if($content)
                    {!! $content !!}
                @else
                    {{ $slot }}
                @endif
            </span>
        </flux:heading>
    </div>
    
    {{-- Copy Button section --}}
    @if($copyable && ($content || $slot))
        <div 
            class="absolute right-0 top-2" 
            x-data="{
                copied: false,
                rawContent: '{{ addslashes(html_entity_decode(preg_replace('/<[^>]*>/', ' ', $content ?? $slot ?? ''))) }}',
                copyText() {
                    navigator.clipboard.writeText(this.rawContent)
                        .then(() => {
                            this.copied = true;
                            $flux.toast({
                                text: '{{ $title }} copied to clipboard.',
                                variant: 'success',
                                timeout: 2000,
                                position: 'top right'
                            });
                            setTimeout(() => { this.copied = false }, 2000);
                        })
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
                <flux:button 
                    size="xs" 
                    icon="clipboard-document"
                    icon:variant="outline"
                    tooltip="Copy {{ $title }} to clipboard."
                    x-on:click="copyText()"
                />
            </div>
            
            <div x-show="copied">
                <flux:button 
                    size="xs" 
                    icon="check"
                    variant="primary"
                    color="green"
                    disabled
                />
            </div>
        </div>
    @endif
</div>