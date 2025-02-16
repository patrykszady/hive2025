@props([
'name',
'links' => null,
'active' => false,
'icon' => null
])

@php
    $classes = 'bg-white text-gray-600 hover:bg-gray-50 hover:text-gray-900 group w-full flex items-center pl-2 pr-1 py-2
    text-left text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500';

    if($active) $classes .= ' bg-gray-100 text-gray';

    if($active){
        $arrow = 'M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0
    010-1.414z';
    }else{
        $arrow = 'M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0
    01-1.414 0z';
    }
@endphp

<div {{-- if :active prop is true open nav section // if routeIs :active is true, expend this section --}}
    x-data="{ open: '{{$active}}' }" class="space-y-1">
    <!-- Current: "bg-gray-100 text-gray-900", Default: "bg-white text-gray-600 hover:bg-gray-50 hover:text-gray-900" -->
    <button @click="open = !open" type="button" class="{{$classes}}" aria-controls="sub-menu-1" aria-expanded="false">
        <!-- Heroicon name: outline/users -->
        <svg class="mr-3 flex-shrink-0 h-6 w-6 text-gray-400 group-hover:text-gray-500"
            xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path d="{{$icon}}" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" />
        </svg>
        <span class="flex-1">
            {{$name}}
        </span>

        <!-- Expanded: "text-gray-400 rotate-90", Collapsed: "text-gray-300" -->

        <svg class="text-gray-300 ml-3 flex-shrink-0 h-5 w-5 transform group-hover:text-gray-400 transition-colors ease-in-out duration-150"
            viewBox="0 0 20 20" aria-hidden="true">
            <path
                x-show="!open"
                fill-rule="evenodd"
                d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                clip-rule="evenodd" fill="currentColor"
                />
            <path
                x-show="open"
                fill-rule="evenodd"
                d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                clip-rule="evenodd" fill="currentColor"
                />
        </svg>
    </button>

    <!-- Expandable link section, show/hide based on state. -->
    <div x-show="open" id="sub-menu-1" class="space-y-1">
        {{$links}}
    </div>
</div>
