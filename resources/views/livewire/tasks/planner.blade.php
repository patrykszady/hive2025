<div>
	<x-page.top
        class="lg:max-w-5xl"
        h1="Project Tasks Timeline"
        p=""
        >
        <x-slot name="right">
            <button
                wire:click="weekToggle('previous')"
                type="button"
                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                < Previous Week
            </button>
            <br class="hidden:md">
            <span>{{$days[0]['formatted_date'] . ' - ' . $days[5]['formatted_date']}}</span>
            <br class="hidden:md">
            <button
                wire:click="weekToggle('next')"
                type="button"
                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                Next Week >
            </button>
        </x-slot>
    </x-page.top>

    <livewire:tasks.planner-project :projects="$projects" :days="$days" />
    <livewire:tasks.task-create :projects="$projects" />
    {{-- @foreach($projects as $project_index => $project)
        <livewire:tasks.planner-project :project="$project" :days="$days" />
    @endforeach --}}
</div>