<flux:card class="space-y-2">
    <flux:accordion transition>
        <flux:accordion.item expanded>
            <div class="flex justify-between">
                <flux:accordion.heading>
                    <flux:heading size="lg" class="mb-0">
                        {{ \Carbon\Carbon::parse($date)->format('l, M jS \'y') }}
                        <flux:badge color="green" inset="top bottom" size="lg" icon="clock" >{{ $hours->sum('hours') }} Hours</flux:badge>
                    </flux:heading>
                </flux:accordion.heading>
            </div>

            <flux:accordion.content>
                <flux:separator variant="subtle"/>
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Hours</flux:table.column>
                            <flux:table.column>Project</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach($hours as $project_name => $daily_project)
                                <flux:table.row>
                                    <flux:table.cell variant="strong">{{$daily_project->hours}}</flux:table.cell>
                                    <flux:table.cell><a wire:navigate.hover href="{{route('projects.show', $daily_project->project->id)}}">{{$daily_project->project->name}}</a></flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
            </flux:accordion.content>
        </flux:accordion.item>
    </flux:accordion>
</flux:card>
