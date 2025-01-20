<x-modals.modal>
    <form wire:submit="save">
        {{-- HEADER --}}
        <x-cards.heading>
            <x-slot name="left">
                <h1>Combine This Estimate with another</h1>
            </x-slot>
        </x-cards.heading>

        {{-- ROWS --}}
        <x-cards.body :class="'space-y-4 my-4'">
            {{-- CLIENT ID --}}
            <x-forms.row
                wire:model.live="estimate_id"
                errorName="estimate_id"
                name="estimate"
                text="Estimate"
                type="dropdown"
                >
                <option value="" readonly>Select Estimate</option>
                @foreach ($estimates as $estimate)
                    <option value="{{$estimate->id}}">{{$estimate->project->project_name}} | Estimate {{$estimate->id}}</option>
                @endforeach
            </x-forms.row>
        </x-cards.body>

        <x-cards.footer>
            <button
                type="button"
                x-on:click="open = false"
                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                Cancel
            </button>
            <button
                type="submit"
                class="inline-flex items-center px-4 py-2 ml-3 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm disabled:opacity-50 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                Combine
            </button>
        </x-cards.footer>
    </form>
</x-modals.modal>
