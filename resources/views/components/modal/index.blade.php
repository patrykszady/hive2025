{{-- https://livewire.laravel.com/screencast/modals/extracting-a-blade-component 11:20 --}}
<div
    x-data="{ open: false }"
    x-modelable="open"
    {{ $attributes }}
    >
    {{ $slot }}
</div>
