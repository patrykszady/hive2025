@props([
    'class' => '',
])

<div {{ $attributes->class(['bg-white dark:bg-zinc-900 rounded-lg shadow-sm border border-zinc-200 dark:border-zinc-700 overflow-hidden', $class]) }}>
    <div class="p-3">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="flex border-t border-zinc-200 dark:border-zinc-700">
            {{ $footer }}
        </div>
    @endisset
</div>
