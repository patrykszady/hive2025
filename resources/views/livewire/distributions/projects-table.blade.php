<x-index-table heading="Projects {{ $type }} Distributions" :paginator="$projects">
    <x-slot:actions>
        @if ($type === 'Without' && $projects->count() > 0)
            <flux:button
                size="sm"
                variant="primary"
                wire:click="$dispatchTo('distributions.distribution-projects-form', 'bulkAssign')"
            >
                Assign All Projects
            </flux:button>
        @endif
    </x-slot:actions>

        <flux:table wire:loading.class="opacity-50 text-opacity-50" class="index-table [:where(&)]:p-0 [:where(&)]:space-y-0">
            <flux:table.columns>
                <flux:table.column class="w-[50%] min-w-0">Projects {{ $type }}</flux:table.column>
                <flux:table.column class="w-[25%]">Profit</flux:table.column>
                <flux:table.column class="w-[25%]">Completed</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($projects as $project)
                    <flux:table.row :key="$project->id">
                        <flux:table.cell>
                            <button
                                type="button"
                                class="w-full text-left"
                                wire:click="$dispatchTo('distributions.distribution-projects-form', 'addDis', [{{ $project->id }}])"
                            >
                                <div>
                                    <b>{{ $project->short_address }}</b>
                                    <br>
                                    <i class="font-normal">{{$project->project_name}}</i>
                                    <br>
                                    {{ $project->client->name }}
                                </div>
                            </button>
                        </flux:table.cell>
                        <flux:table.cell>{{ money($project->finances['profit']) }}</flux:table.cell>
                        <flux:table.cell>{{ $project->statuses->first()->start_date->format('F Y') }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
</x-index-table>
