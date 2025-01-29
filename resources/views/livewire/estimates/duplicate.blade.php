<x-modals.modal>
    <form wire:submit="save">
        {{-- HEADER --}}
        <x-cards.heading>
            <x-slot name="left">
                <h1>Duplicate This Estimate</h1>
            </x-slot>
        </x-cards.heading>

        {{-- ROWS --}}
        <x-cards.body :class="'space-y-4 my-4'">
            {{-- CLIENT ID --}}
            <x-forms.row
                wire:model.live="client_id"
                errorName="client_id"
                name="client_id"
                text="Client"
                type="dropdown"
                >
                <option value="" readonly>Select Client</option>
                @foreach ($clients as $client)
                    <option value="{{$client->id}}">{{$client->name}}</option>
                @endforeach
            </x-forms.row>


            {{-- CLIENT PROJECTS --}}
            <div
                x-data="{ client: @entangle('client_id') }"
                x-show="client"
                x-transition
                class="my-4 space-y-4"
                >
                <x-forms.row
                    wire:model.live="project_id"
                    errorName="project_id"
                    name="project_id"
                    text="Project"
                    type="dropdown"
                    >
                    <option value="" readonly>Select Project</option>
                    @foreach ($client_projects as $client_project)
                        <option value="{{$client_project->id}}">{{$client_project->project_name}}</option>
                    @endforeach
                </x-forms.row>
            </div>
        </x-cards.body>

        <x-cards.footer>
            <button
                {{-- wire:click="$emitTo('expenses.expenses-new-form', 'resetModal')" --}}
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
                Duplicate
            </button>
        </x-cards.footer>
    </form>
</x-modals.modal>
