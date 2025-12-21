<flux:card>
    <div class="flex justify-between">
        <flux:heading size="lg">
            Projects <b>{{ $type }}</b> Distributions
        </flux:heading>
    </div>

    <div class="space-y-2">
        <flux:table :paginate="$projects->hasPages() ? $projects : null" wire:loading.class="opacity-50 text-opacity-50">
            <flux:table.columns>
                <flux:table.column>Projects {{ $type }}</flux:table.column>
                <flux:table.column>Profit</flux:table.column>
                <flux:table.column>Completed</flux:table.column>
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
    </div>
</flux:card>
