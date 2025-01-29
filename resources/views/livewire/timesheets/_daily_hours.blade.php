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
                        <flux:columns>
                            <flux:column>Hours</flux:column>
                            <flux:column>Project</flux:column>
                        </flux:columns>

                        <flux:rows>
                            @foreach($hours as $project_name => $daily_project)
                                <flux:row>
                                    <flux:cell variant="strong">{{$daily_project->hours}}</flux:cell>
                                    <flux:cell><a wire:navigate.hover href="{{route('projects.show', $daily_project->project->id)}}">{{$daily_project->project->name}}</a></flux:cell>
                                </flux:row>
                            @endforeach
                        </flux:rows>
                    </flux:table>
            </flux:accordion.content>
        </flux:accordion.item>
    </flux:accordion>
</flux:card>
