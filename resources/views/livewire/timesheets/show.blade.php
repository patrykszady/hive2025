<div class="grid grid-cols-4 gap-4 xl:relative sm:px-6 lg:max-w-5xl">
    <div class="col-span-4 space-y-4 lg:col-span-2 lg:sticky lg:top-5">
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
        <x-island-card heading="Week of {{ $timesheet->date->format('m/d/Y') }}" :separator="true">
            <x-slot:actions>
                @if($not_paid)
                    <flux:button
                        wire:click="revert"
                        size="sm"
                        variant="danger"
                        icon="arrow-uturn-left"
                        >
                        Revert Timesheet
                    </flux:button>
                @endif
            </x-slot:actions>

            <div class="space-y-2" x-data="{ hoveredPaymentGroup: null }">
                <flux:table class="w-full">
                    <flux:table.columns>
                        <flux:table.column>Amount</flux:table.column>
                        <flux:table.column class="w-14">Hours</flux:table.column>
                        <flux:table.column>Project</flux:table.column>
                        <flux:table.column>Payment</flux:table.column>
                        <flux:table.column class="w-16">Status</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach($weekly_hours as $timesheet)
                            <flux:table.row :key="$timesheet->id">
                                <flux:table.cell variant="strong">
                                    <a
                                        wire:navigate.hover
                                        href="{{$timesheet->check ? route('checks.show', $timesheet->check->id) : (!$timesheet->check && $timesheet->check_id ? '' : (auth()->user()->vendor_role === 'Admin' ? route('timesheets.payment', $timesheet->user_id) : ''))}}"
                                        class="transition-colors hover:text-indigo-600 dark:hover:text-indigo-400"
                                    >
                                        {{ money($timesheet->amount) }}
                                    </a>
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">{{ $timesheet->hours}}</flux:table.cell>
                                <flux:table.cell>
                                    <a
                                        wire:navigate.hover
                                        href="{{route('projects.show', $timesheet->project->id)}}"
                                        class="block truncate transition-colors hover:text-indigo-600 dark:hover:text-indigo-400"
                                    >
                                        {{ Str::limit($timesheet->project->name, 15) }}
                                    </a>
                                </flux:table.cell>

                                @if($timesheet->check)
                                    <flux:table.cell
                                        x-on:mouseenter="hoveredPaymentGroup = '{{ $timesheet->payment_group_key }}'"
                                        x-on:mouseleave="hoveredPaymentGroup = null"
                                    >
                                        <a
                                            wire:navigate.hover
                                            href="{{ route('checks.show', $timesheet->check->id) }}"
                                            class="block truncate transition-colors hover:text-indigo-600 dark:hover:text-indigo-400"
                                            x-bind:class="hoveredPaymentGroup && hoveredPaymentGroup === '{{ $timesheet->payment_group_key }}' ? 'text-indigo-600 dark:text-indigo-400' : ''"
                                        >
                                            {{ $timesheet->check->check_type != 'Check' ? Str::substr($timesheet->check->check_type, 0, 1) . ' #' . $timesheet->check->id : $timesheet->check->check_number }}
                                        </a>
                                    </flux:table.cell>
                                @elseif(!$timesheet->check && $timesheet->check_id && !$timesheet->vendor_id)
                                    <flux:table.cell
                                        x-on:mouseenter="hoveredPaymentGroup = '{{ $timesheet->payment_group_key }}'"
                                        x-on:mouseleave="hoveredPaymentGroup = null"
                                    >
                                        <span
                                            class="transition-colors"
                                            x-bind:class="hoveredPaymentGroup && hoveredPaymentGroup === '{{ $timesheet->payment_group_key }}' ? 'text-indigo-600 dark:text-indigo-400' : ''"
                                        >Paid By</span>
                                    </flux:table.cell>
                                @else
                                    <flux:table.cell></flux:table.cell>
                                @endif

                                <flux:table.cell>
                                    @if($timesheet->status == 'Pay' && auth()->user()->vendor_role === 'Admin')
                                        <a wire:navigate.hover href="{{ route('timesheets.payment', $timesheet->user_id) }}">
                                            <flux:badge 
                                                size="sm" 
                                                color="yellow" 
                                                inset="top bottom"
                                            >
                                                {{ $timesheet->status }}
                                            </flux:badge>
                                        </a>
                                    @else
                                        <flux:badge 
                                            size="sm" 
                                            :color="$timesheet->status == 'Paid' ? 'green' : ($timesheet->status == 'Not Paid' ? 'red' : 'yellow')" 
                                            inset="top bottom"
                                        >
                                            {{ $timesheet->status }}
                                        </flux:badge>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </x-island-card>
    </div>

    <div class="col-span-4 space-y-2 lg:col-span-2 lg:col-start-3 overflow-y-auto">
        {{-- DAILY HOURS --}}
        @foreach($daily_hours as $date => $hours)
            @include('livewire.timesheets._daily_hours')
        @endforeach
    </div>

</div>
