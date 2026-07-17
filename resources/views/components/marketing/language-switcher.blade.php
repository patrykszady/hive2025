@php
    $supported = config('locales.supported', []);
    $current = app()->getLocale();
    $currentMeta = $supported[$current] ?? ['native' => strtoupper($current)];
@endphp

<flux:dropdown position="bottom" align="end">
    <flux:button variant="ghost" icon="language" icon:trailing="chevron-down" size="sm" class="!px-2">
        <span class="max-sm:hidden">{{ $currentMeta['native'] }}</span>
    </flux:button>
    <flux:navmenu>
        @foreach ($supported as $code => $meta)
            <flux:navmenu.item
                href="{{ locale_alternate_url($code) }}"
                :current="$code === $current"
                icon="{{ $code === $current ? 'check' : '' }}"
            >{{ $meta['native'] }}</flux:navmenu.item>
        @endforeach
    </flux:navmenu>
</flux:dropdown>
