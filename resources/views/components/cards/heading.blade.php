@aware([
    'accordian' => NULL,
])

@props([
    'exclude_accordian_button_text' => NULL,
])

{{-- isset($accordian) ? 'pl-2 ' : 'pl-6 ' .  --}}
{{-- {{$attributes->merge(['class' => 'pr-4 py-4 border-b border-gray-200'])}} --}}
<div class="{{isset($accordian) ? '' : 'pl-6'}} pr-4 py-4 border-b border-gray-200">
    <div class="flex mx-auto items-center justify-between">
        <div class="flex items-center justify-between">
            @if(isset($accordian))
                <button
                    x-disclosure:button
                    class="flex pl-0 mr-4 justify-between items-center ml-2"
                    >
                    <span x-show="$disclosure.isOpen" x-cloak aria-hidden="true">
                        <svg
                            x-transition.duration.250ms
                            class="w-5 h-5 ml-auto text-gray-400 shrink-0" viewBox="0 0 20 20" fill="currentColor"
                            aria-hidden="true"
                            >
                            <path fill-rule="evenodd"
                                d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                                clip-rule="evenodd" />
                        </svg>
                    </span>
                    <span x-show="!$disclosure.isOpen" aria-hidden="true">
                        <svg
                            x-transition.duration.250ms
                            class="w-5 h-5 ml-auto text-gray-400 shrink-0" viewBox="0 0 20 20" fill="currentColor"
                            aria-hidden="true"
                            >
                            <path fill-rule="evenodd"
                                d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z"
                                clip-rule="evenodd" />
                        </svg>
                    </span>
                    @if(!$exclude_accordian_button_text)
                        <span class="font-medium">{{$left ?? ''}}</span>
                    @endif
                </button>
                @if($exclude_accordian_button_text)
                    <span class="font-medium">{{$left ?? ''}}</span>
                @endif
            @else
                <span class="font-medium">{{$left ?? ''}}</span>
            @endif
        </div>

        {{--  10/14/21 only last inside x-card.heading = flex-shrink-0 .. how to do automatically? --}}
        {{-- mt-2 md:mt-0 --}}
        <div class="flex-shrink-0 md:ml-4">
            {{-- 10/14/21 button = new compnent in card or application? --}}
            {{$right ?? ''}}
        </div>
    </div>
    {{$slot}}
</div>
