{{-- See search_li.blade.php for similar --}}
{{-- search_li.blade is similar with href and wire:click --}}

@props([
    'hrefTarget' => NULL,
    //4-26-2023 remove white_button everywhere
    'button_color' => 'indigo',
])

@php
    if($button_color == 'white'){
        $classes = "bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500";
    }else{
        $classes = "relative inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm rounded-md text-white bg-$button_color-600 hover:bg-$button_color-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-$button_color-500";
    }
@endphp

@if(isset($attributes['wire:click']))
    <button
        {{$attributes}}
        type="button"
        class="{{$classes}}"
        wire:click="{{ $attributes['wire:click'] }}"
        >
        {{$slot}}
    </button>
@elseif($attributes['href'] == "")

@else
    <a
        href="{{ $attributes['href'] }}"

        @if($hrefTarget)
            target="{{$hrefTarget}}"
        @endif

        {{$attributes}}
        class="{{$classes}}"
        >
        {{$slot}}
    </a>
@endif
