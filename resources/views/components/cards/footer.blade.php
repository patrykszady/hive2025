@props([
    // = has unordered list / strip/remove classes
    'has_ul' => NULL,
])

{{-- 8/28/24 can combine into one and remove @if. See how accordian and livewire sort does it --}}
@if(isset($has_ul))
    <div class="border-t border-gray-200 bg-gray-50 rounded-b-lg">
        {{$slot}}
    </div>
@else
    <div class="items-center justify-between px-6 py-3 border-t border-gray-200 bg-gray-50 lg:flex rounded-b-lg">
        {{-- sm:flex-1 sm:flex sm:items-center sm:justify-between --}}
        <div class="flex items-center justify-between flex-1">
            {{$slot}}
        </div>
    </div>
@endif

@if(isset($bottom))
    <div class="items-center justify-between px-6 py-3 text-center border-t border-gray-200 bg-gray-50 lg:flex">
        <div class="flex items-center justify-between flex-1 text-center">
            {{$bottom}}
        </div>
    </div>
@endif
