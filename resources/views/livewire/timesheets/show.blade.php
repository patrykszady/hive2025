<x-page.shell
    :cols="4"
    :breadcrumbs="[
        ['label' => 'Timesheets', 'href' => route('timesheets.index')],
        ['label' => ($timesheet->user->nickname ?: $timesheet->user->first_name).' · Week of '.$timesheet->date->format('m/d/Y')],
    ]"
>
    <x-page.column :span="2" class="lg:sticky lg:top-5">
        {{-- TIMESHEET DETAILS --}}
        <x-details.card title="Timesheet Week Details" :separator="false">
            <x-slot:details>
                <x-details.row title="Team Member" :content="$timesheet->user->full_name" :href="route('users.show', $timesheet->user)" />
                <x-details.row title="Week Of" :content="$timesheet->date->format('m/d/Y')" />
                <x-details.row title="Week Total" :content="money($weekly_hours->sum('amount'))" />
                <x-details.row title="Week Hours" :content="(string) $weekly_hours->sum('hours')" />
                <x-details.row title="Hourly" :content="money($timesheet->hourly)" />
            </x-slot:details>
        </x-details.card>

        {{-- WEEKLY GROUPED --}}
        <x-index-table heading="Week of {{ $timesheet->date->format('m/d/Y') }}" x-data="{ hoveredPaymentGroup: null }">
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

            @php($columns = \App\Livewire\Timesheets\TimesheetShow::columnDefs())
            <x-index-table.table :columns="$columns">
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
                        {{-- Shared row link: truncates to the column and only
                             shows a tooltip when the name is clipped. --}}
                        <flux:table.cell class="min-w-0">
                            <x-table-link
                                :href="route('projects.show', $timesheet->project->id)"
                                :label="$timesheet->project->name"
                            />
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
            </x-index-table.table>
        </x-index-table>
    </x-page.column>

    <x-page.column :span="2" class="overflow-y-auto">
        {{-- DAILY HOURS --}}
        @foreach($daily_hours as $date => $hours)
            @include('livewire.timesheets._daily_hours')
        @endforeach
    </x-page.column>
</x-page.shell>
