{{-- <div class="relative">
    <div class="absolute inset-0 flex items-center" aria-hidden="true">
        <div class="w-full border-t border-gray-300"></div>
    </div>
    <div class="relative flex justify-center">
        <span class="px-2 text-sm text-gray-500 bg-white">
            {{$slot}}
        </span>
    </div>
</div> --}}
@props([
    'sectionclass' => 'text-gray-600 bg-white',
    'padding' => 'sm:pl-52'
])

<div class="relative mt-4 {{$padding}} {{$attributes['class']}}">
    <div class="absolute inset-0 flex items-center" aria-hidden="true">
        <div class="w-full border-t border-gray-300"></div>
    </div>
    <div class="relative flex justify-center">
        {{-- hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 --}}
        <div
            class="inline-flex items-center shadow-sm px-4 py-1.5 border border-gray-300 text-sm leading-5 font-small rounded-full text-white {{$sectionclass}}">
            <!-- Heroicon name: solid/plus-sm -->
            {{-- <svg class="-ml-1.5 mr-1 h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd"
                    d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z"
                    clip-rule="evenodd" />
            </svg> --}}
            <span>{{$slot}}</span>
        </div>
    </div>
</div>
