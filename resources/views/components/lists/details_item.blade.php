@props([
    'title' => null,
    'detail' => null,
    'target' => null,
    'wire_click' => null
])

<div class="py-3 grid grid-cols-3 gap-4">
    <dt class="text-sm font-medium text-gray-900 dark:text-white">{{$title}}</dt>
    <dd class="text-sm text-gray-700 col-start-2 col-span-2 dark:text-white">
        @if(isset($attributes['wire:click']) || $attributes['href'])
            <a
                class="cursor-pointer"
                @if(isset($attributes['wire:click']))
                    {{-- href="javascript:void(0);" --}}
                    wire:click="{{ $attributes['wire:click'] }}"
                @elseif(isset($attributes['href']))
                    href="{{ $attributes['href'] }}"
                @else
                    href="#"
                @endif

                @if($target)
                    target="{{$target}}"
                @else
                    wire:navigate.hover
                @endif
                >

                {!!$detail!!}
            </a>
        @else
            {!!$detail!!}
        @endif
    </dd>
</div>
