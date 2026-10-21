{{-- The expense form's project/distribution options: projects (active first)
     with their status, then distributions as "D:{id}" — the encoding
     ExpenseAccessGuard::resolveProjectSelector() reads. Shared by the expense
     form and the bulk "Link transactions" modal. --}}
@foreach($projects as $project)
    <flux:select.option wire:key="{{$project->id}}" value="{{$project->id}}">
        <div class="flex flex-col gap-0 w-full">
            <div class="flex items-center w-full">
                <span class="flex-1 min-w-0">{{ $project->short_address }}</span>

                @if($project->latestStatus)
                    <flux:badge size="sm" :color="$project->latestStatus->badge_color" inset="top bottom" class="shrink-0 ml-2">
                        {{ $project->latestStatus->title }}
                    </flux:badge>
                @endif
            </div>

            <i class="font-normal block w-full leading-tight">{{$project->project_name}}</i>
        </div>
    </flux:select.option>
@endforeach

<flux:select.option disabled>--------------</flux:select.option>

@foreach($distributions as $distribution)
    <flux:select.option wire:key="D:{{$distribution->id}}" value="D:{{$distribution->id}}">{{$distribution->name}}</flux:select.option>
@endforeach
