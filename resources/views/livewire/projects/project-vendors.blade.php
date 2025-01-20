<x-modal wire:model="showModal">
    <x-modal.panel>
        <form wire:submit="save">
            {{-- HEADER --}}
            <x-cards.heading>
                <x-slot name="left">
                    <h1>Invite Contractors to Join Project</h1>
                </x-slot>
            </x-cards.heading>

            {{-- ROWS --}}
            <x-cards.body :class="'space-y-4 my-4'">
                {{-- DURATION --}}
                <x-forms.row
                    wire:model.live="vendor_id"
                    errorName="vendor_id"
                    name="vendor_id"
                    text="Vendor"
                    type="dropdown"
                    >
                    <option value="">Choose Vendor</option>
                    @foreach($vendors as $vendor)
                        <option value="{{$vendor->id}}">{{$vendor->name}}</option>
                    @endforeach
                </x-forms.row>

                {{-- PROJECT --}}
                {{-- <x-forms.row
                    wire:model.live="form.project_id"
                    errorName="form.project_id"
                    name="project_id"
                    text="Project"
                    type="dropdown"
                    >
                    <option value="" readonly>Select Project</option>
                    @foreach ($projects as $project)
                        <option value="{{$project->id}}">{{$project->name}}</option>
                    @endforeach
                </x-forms.row> --}}
            </x-cards.body>

            <x-cards.footer>
                <button
                    type="button"
                    x-on:click="open = false"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    >
                    Cancel
                </button>
                <div
                    {{-- x-data="{ estimate_line_item: @entangle('estimate_line_item') }"
                    x-show="estimate_line_item" --}}
                    >
                    {{-- <button
                        wire:click="removeTask"
                        type="button"
                        x-on:click="open = false"
                        class="px-4 py-2 text-sm font-medium text-red-700 bg-white border border-red-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                        >
                        Remove
                    </button> --}}
                </div>
                <button
                    type="submit"
                    class="inline-flex items-center px-4 py-2 ml-3 text-sm text-white bg-indigo-600 border border-transparent rounded-md shadow-sm font-small disabled:opacity-50 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    >
                    Invite
                </button>
            </x-cards.footer>
        </form>
    </x-modal.panel>
</x-modal>
