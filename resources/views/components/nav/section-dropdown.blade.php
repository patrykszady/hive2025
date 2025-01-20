@props(['active' => false])

@php
    $classes = 'group w-full flex items-center pl-11 pr-2 py-2 text-sm font-medium rounded-md hover:text-gray-900 hover:bg-gray-50';

    if($active) $classes .= ' bg-indigo-800 text-white';
    // else .. default
@endphp

<a
    {{-- 'class' $attributes get added on (value + below). Any other $atributes like href or type, if present, get replaced. 
        If not present the defaul value below is used.                
    --}}

    {{$attributes->merge([
        'class' => $classes, 
        'href' => '#'
        ])}}
    >

    {{$slot}}

</a>