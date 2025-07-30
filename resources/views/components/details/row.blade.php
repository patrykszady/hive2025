@props([
    'title',
    'content' => null,
    'href' => null,
    'copyable' => false,
])

<div class="relative flex flex-col sm:grid sm:grid-cols-4 gap-1 items-start py-2 sm:py-3 [&:not(:last-child)]:border-b [&:not(:last-child)]:border-zinc-800/15 dark:[&:not(:last-child)]:border-white/20">
    <flux:subheading class="truncate">{{ $title }}</flux:subheading>
    
    {{-- Content --}}
    <div class="w-full sm:w-auto sm:col-span-3 {{ $copyable ? 'sm:pr-8' : '' }}">
        <flux:heading class="!my-0 truncate max-w-full">
            @if($href)
                <flux:link 
                    href="{{ $href }}" 
                    external
                    variant="ghost"
                    :accent="false"
                    class="inline-block"
                    >
                    {!! $content !!}
                </flux:link>
            @else
                {{ $content ?? $slot }}
            @endif
        </flux:heading>
    </div>
    
    {{-- Copy Button - With universal HTML tag handling --}}
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