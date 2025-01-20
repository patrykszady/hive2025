<div class="inline-flex rounded-md shadow-sm">
    <div class="relative block -ml-px">
        <label for="sections.{{$index}}.bid_index" class="sr-only">Select Estimate Bid</label>
        <select
            wire:model.live="sections.{{$index}}.bid_index"
            errorName="sections.{{$index}}.bid_index"
            name="sections.{{$index}}.bid_index"
            id="sections.{{$index}}.bid_index"
            class="block w-full rounded-r-none rounded-l-md border-0 bg-white py-1.5 pl-3 pr-9 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
            >
            <option value="" readonly>Select Bid</option>

            @foreach($bids as $bid_index => $bid)
                <option
                    value="{{$bid_index}}"
                    {{-- x-bind:disabled="{{$bid->estimate_sections->isEmpty() && $bid->amount != 0.00}}" --}}
                    >
                    {{$bid->name}}
                </option>
            @endforeach
        </select>
    </div>

    <button
        wire:click="newEstimateBid({{$index}})"
        type="button"
        class="relative inline-flex items-center px-3 -ml-px text-sm font-semibold text-gray-600 bg-white rounded-r-md ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-10"
        >
        New
    </button>
</div>
