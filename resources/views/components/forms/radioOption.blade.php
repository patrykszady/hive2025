<!--
    Checked: "border-transparent", Not Checked: "border-gray-300"
    Active: "ring-2 ring-indigo-500"
-->

<label
    x-data="{ option: '{{$value}}', {{$model}}: @entangle($model).live }"
    @click="select(option)"
    role="none"
    class="bg-white border rounded-lg shadow-sm px-6 py-4 cursor-pointer sm:flex sm:justify-between focus:outline-none hover:bg-gray-50"
    :class="{ 'ring-2 ring-indigo-500 bg-gray-100': isSelected(option) }"   
    >
    <input 
        type="radio"
        value="{{$value}}" 
        class="sr-only"
        x-model="{{$model}}"
        aria-labelledby="{{$ariaLabelledby}}"
        aria-describedby="{{$ariaDescribedby}}" 
        
        >
        <div class="flex items-center">
            <div class="text-sm">
                <p class="text-lg font-medium text-indigo-600">
                    {{$title}}
                </p>
                <div class="text-gray-500">
                    <p class="sm:inline">{{$desc}}</p>
                    {{-- <span class="hidden sm:inline sm:mx-1" aria-hidden="true">&middot;</span>
                    <p class="sm:inline">160 GB SSD disk</p> --}}
                </div>
            </div>
        </div>
        
        <div class="mt-2 flex text-sm sm:mt-0 sm:block sm:ml-4 sm:text-right">
            <div class="ml-2 flex-shrink-0 flex">
                <p
                    class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-indigo-100 text-indigo-800">
                    {{ $bubbleMessage }}
                </p>
            </div>
        </div>
        <!--
    Active: "border", Not Active: "border-2"
    Checked: "border-indigo-500", Not Checked: "border-transparent"
    -->
    {{-- <div 
        class="absolute inset-px rounded-lg pointer-events-none"
        :class="{ 'border': isSelected(option) }"   
        aria-hidden="true"
        >
    </div> --}}
</label>