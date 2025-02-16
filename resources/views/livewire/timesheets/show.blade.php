<div class="grid grid-cols-4 gap-4 xl:relative sm:px-6 lg:max-w-5xl">
    <div class="col-span-4 space-y-4 lg:col-span-2 lg:h-32 lg:sticky lg:top-5">
        {{-- TIMESHEET DETAILS --}}
        <x-lists.details_card>
            {{-- HEADING --}}
            <x-slot:heading>
                <flux:heading size="lg" class="mb-0">Timesheet Week Details</flux:heading>
            </x-slot>

            {{-- DETAILS --}}
            <x-lists.details_list>
                <x-lists.details_item title="Team Member" detail="{{$timesheet->user->full_name}}" href="{{route('users.show', $timesheet->user)}}" />
                <x-lists.details_item title="Week Of" detail="{{$timesheet->date->format('m/d/Y')}}" />
                <x-lists.details_item title="Week Total" detail="{{money($weekly_hours->sum('amount'))}}" />
                <x-lists.details_item title="Week Hours" detail="{{$weekly_hours->sum('hours')}}" />
                <x-lists.details_item title="Hourly" detail="{{money($timesheet->hourly)}}" />
            </x-lists.details_list>
        </x-lists.details_card>

        {{-- WEEKLY GROUPED --}}
        <flux:card class="space-y-2">
            <div>
                <flux:heading size="lg">Week of <b>{{$timesheet->date->format('m/d/Y')}}</b></flux:heading>
            </div>
            <flux:separator variant="subtle" />

            <div class="space-y-2">
                <flux:table>
                    <flux:columns>
                        <flux:column>Amount</flux:column>
                        <flux:column>Hours</flux:column>
                        <flux:column>Project</flux:column>
                        <flux:column>Payment</flux:column>
                        <flux:column>Status</flux:column>
                    </flux:columns>

                    <flux:rows>
                        @foreach($weekly_hours as $timesheet)
                            <flux:row :key="$timesheet->id">
                                <flux:cell variant="strong">
                                    <a wire:navigate.hover href="{{$timesheet->check ? route('checks.show', $timesheet->check->id) : (!$timesheet->check && $timesheet->check_id ? '' : (auth()->user()->primary_vendor->pivot->role_id == 1 ? route('timesheets.payment', $timesheet->user_id) : ''))}}">{{ money($timesheet->amount) }}</a>
                                </flux:cell>
                                <flux:cell>{{ $timesheet->hours}}</flux:cell>
                                <flux:cell>
                                    <a wire:navigate.hover href="{{route('projects.show', $timesheet->project->id)}}">{{ Str::limit($timesheet->project->name, 15) }}</a>
                                </flux:cell>

                                @if($timesheet->check)
                                    <flux:cell>{{ $timesheet->check && $timesheet->check_id ? $timesheet->check->check_type != 'Check' ? $timesheet->check->check_type . ' #' . $timesheet->check->id : $timesheet->check->check_number : '' }}</flux:cell>
                                @elseif(!$timesheet->check && $timesheet->check_id && !$timesheet->vendor_id)
                                    <flux:cell>Paid By</flux:cell>
                                @else
                                    <flux:cell></flux:cell>
                                @endif

                                <flux:cell>
                                    <flux:badge size="sm" :color="$timesheet->paid_by || $timesheet->check_id ? 'green' : 'yellow'" inset="top bottom">{{ $timesheet->paid_by ? 'Paid By' : ($timesheet->check_id ? 'Paid' : (auth()->user()->primary_vendor->pivot->role_id == 1 ? 'Pay' : 'Not Paid')) }}</flux:badge>
                                </flux:cell>
                            </flux:row>
                        @endforeach
                    </flux:rows>
                </flux:table>
            </div>
        </flux:card>
    </div>

    <div class="col-span-4 space-y-2 lg:col-span-2 lg:col-start-3 overflow-y-auto">
        {{-- DAILY HOURS --}}
        @foreach($daily_hours as $date => $hours)
            @include('livewire.timesheets._daily_hours')
        @endforeach
    </div>
</div>
