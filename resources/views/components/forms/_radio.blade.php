<fieldset
    class="w-full"
    x-data="{
        value: null,
        select(option) { this.value = option },
        isSelected(option) { return this.value === option },
        }"
    role="radiogroup"
    >
{{-- 
    <legend class="sr-only">
        x-text="User Vendors"
        User Vendors
    </legend> --}}

    <div class="space-y-4">
        <!--
      Checked: "border-transparent", Not Checked: "border-gray-300"
      Active: "ring-2 ring-indigo-500"
    -->
        @foreach ($attributes['options']  as $option)
            <label
                x-data="{ option: '{{$option->id}}', vendor_id: @entangle('vendor_id').live }"
                @click="select(option)"
                role="none"
                class="bg-white border rounded-lg shadow-sm px-6 py-4 cursor-pointer sm:flex sm:justify-between focus:outline-none hover:bg-gray-50"
                :class="{ 'ring-2 ring-indigo-500 bg-gray-100': isSelected(option) }"   
                >
                <input 
                    type="radio"
                    {{-- role="radio" --}}
                    {{-- id="{{$option->id}}"  --}}
                    value="{{$option->id}}" 
                    class="sr-only"
                    x-model="vendor_id"
                    {{-- name="vendor_id"  --}}
                    aria-labelledby="server-size-{{$option->id}}-label"
                    aria-describedby="server-size-{{$option->id}}-description-0 server-size-{{$option->id}}-description-1" 
                    
                    >
                    <div class="flex items-center">
                        <div class="text-sm">
                            <p id="server-size-{{$option->id}}-label" class="font-medium text-gray-900">
                                {{$option->business_name}}
                            </p>
                            <div id="server-size-{{$option->id}}-description-0" class="text-gray-500">
                                <p class="sm:inline">8GB / 4 CPUs</p>
                                <span class="hidden sm:inline sm:mx-1" aria-hidden="true">&middot;</span>
                                <p class="sm:inline">160 GB SSD disk</p>
                            </div>
                        </div>
                    </div>
                    <div id="server-size-{{$option->id}}-description-1" class="mt-2 flex text-sm sm:mt-0 sm:block sm:ml-4 sm:text-right">
                        <div class="font-medium text-gray-900">$40</div>
                        <div class="ml-1 text-gray-500 sm:ml-0">/mo</div>
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
        @endforeach
    </div>
</fieldset>