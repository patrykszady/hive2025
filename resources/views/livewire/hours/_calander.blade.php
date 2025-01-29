<div class="text-center">
    <div class="flex items-center text-gray-900">
        <div class="flex-auto font-semibold">
            {{$this->selected_date->format('F Y')}}
        </div>
    </div>
    <div class="grid grid-cols-7 mt-6 text-xs leading-6 text-gray-500">
        <div>M</div>
        <div>T</div>
        <div>W</div>
        <div>T</div>
        <div>F</div>
        <div>S</div>
        <div>S</div>
    </div>
    <div class="grid grid-cols-7 gap-px mt-2 text-sm bg-gray-200 rounded-lg shadow isolate ring-1 ring-gray-200">
        <!--
            Always include: "py-1.5 hover:bg-gray-100 focus:z-10"
            Top left day, include: "rounded-tl-lg"
            Top right day, include: "rounded-tr-lg"
            Bottom left day, include: "rounded-bl-lg"
            Bottom right day, include: "rounded-br-lg"
            Is current month, include: "bg-white"
            Is today and is not selected, include: "text-indigo-600"
            Is not selected, is not today, and is current month, include: "text-gray-900"
            Is not selected, is not today, and is not current month, include: "text-gray-400"

            Is not current month, include: ""
            Is selected or is today, include: "font-semibold"
            Is selected, include: "text-white"
        -->
        @foreach($days as $day_index =>  $day)
            <button
                type="button"
                wire:click="$dispatch('selectedDate', { date: '{{$day['format']}}', day_index: '{{$day_index}}' })"
                {{-- if date is in CONFIRMED ARRAY --}}
                @disabled(today()->format('Y-m-d') < $day['format'] || $day['confirmed_date'] == TRUE)

                class="py-1.5 focus:z-10
                    @if(today()->format('Y-m-d') < $day['format'] || $day['confirmed_date'] == TRUE)
                        ' cursor-not-allowed text-gray-400 bg-gray-200 '
                    @else
                        ' hover:bg-sky-400 hover:text-white hover:font-semibold '
                    @endif

                    @if(today()->format('Y-m-d') == $day['format'] && $day['has_hours'] == TRUE)
                        ' text-white bg-green-500 '
                    @elseif(today()->format('Y-m-d') == $day['format'])
                        ' font-semibold '
                    @elseif($day['month'] == today()->format('m') && $day['has_hours'] == TRUE && $day['confirmed_date'] == FALSE)
                        ' bg-green-500 text-white '
                    @elseif($day['month'] == today()->format('m') && $day['has_hours'] == TRUE && $day['confirmed_date'] == TRUE)
                        ' bg-green-100 text-white '
                    @elseif($day['month'] == today()->format('m'))
                        '  bg-gray-100 '
                    @elseif($day['has_hours'] == TRUE)
                        ' bg-green-100 '
                    @else
                        ' bg-gray-200 text-gray-400 '
                    @endif

                    @if($this->selected_date)
                        @if($this->selected_date->format('Y-m-d') == $day['format'])
                            ' font-bold text-white bg-sky-600 '
                        @endif
                    @endif
                    {{-- @if($loop->iteration == 1)
                        ' rounded-tl-lg'
                    @elseif($loop->iteration == 7)
                        ' rounded-tr-lg'
                    @elseif($loop->iteration == 29)
                        ' rounded-bl-lg'
                    @elseif($loop->iteration == 35)
                        ' rounded-br-lg'
                    @endif --}}
                    ">
                <time datetime="{{$day['format']}}" class="flex items-center justify-center mx-auto rounded-full h-7 w-7">{{$day['day']}}</time>
            </button>
        @endforeach
    </div>
</div>
