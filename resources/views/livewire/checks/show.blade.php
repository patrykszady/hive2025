<div>
    <div class="grid max-w-xl grid-cols-5 gap-4 xl:relative lg:max-w-5xl sm:px-6">
        <div class="col-span-5 space-y-4 lg:col-span-2 lg:h-32 lg:sticky lg:top-5">
            {{-- CHECK DETAILS --}}
            <x-details.card title="Check Details" :canEdit="auth()->user()->can('update', $check)">
                <x-slot:header_buttons>                 
                    <flux:button
                        wire:click="$dispatchTo('checks.check-create', 'editCheck', { check: {{$check->id}}})"
                        size="sm"
                    >
                        Edit Check
                    </flux:button>
                </x-slot:header_buttons>

                <x-slot:details>
                    <x-details.row title="Amount" :content="money($check->amount)" />
                    <x-details.row title="Payee" :content="$check->owner" href="{{$check->vendor_id ? route('vendors.show', $check->vendor->id) : ($check->user_id ? route('users.show', $check->user->id) : '')}}"/>
                    <x-details.row title="Date" :content="$check->date->format('m/d/Y')" />
                    @if($check->bank_account)
                        <x-details.row title="Bank" :content="$check->bank_account->getNameAndType()" />
                    @endif
                    <x-details.row title="Type" :content="$check->check_type" />
                    <x-details.row
                        :title="$check->payment_label"
                        :content="$check->check_number"
                    />
                </x-slot:details>
            </x-details.card>

            {{-- CHECK TRANSACTIONS --}}
            @if($check->transactions->isNotEmpty())
                <x-transactions.list_card 
                    :transactions="$check->transactions" 
                    title="Transactions"
                />
            @endif
        </div>

        <div class="col-span-5 space-y-2 lg:col-span-3">
            {{-- USER TIMESHEETS --}}
            @if(!$weekly_timesheets->isEmpty())
                <x-island-card heading="{{ $check->user->first_name }}'s Timesheets">

                    @foreach($weekly_timesheets->groupBy('date') as $weekly_project_timesheets)
                        <flux:card>
                            <div class="flex justify-between">
                                <flux:heading size="lg">{{'Week of ' . $weekly_project_timesheets->first()->date->startOfWeek()->toFormattedDateString()}}</flux:heading>
                                <flux:button disabled>
                                    {{ money($weekly_project_timesheets->sum('amount')) }}
                                </flux:button>
                            </div>

                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>Amount</flux:table.column>
                                    <flux:table.column>Hours</flux:table.column>
                                    <flux:table.column>Project</flux:table.column>
                                </flux:table.columns>

                                <flux:table.rows>
                                    @foreach($weekly_project_timesheets as $key => $project_timesheet)
                                        <flux:table.row :key="$project_timesheet->id">
                                            <flux:table.cell>
                                                <a wire:navigate.hover href="{{route('timesheets.show', $project_timesheet->id)}}">{{ money($project_timesheet->amount) }}</a>
                                            </flux:table.cell>
                                            <flux:table.cell>{{ $project_timesheet->hours }}</flux:table.cell>
                                            <flux:table.cell>
                                                <a wire:navigate.hover href="{{route('projects.show', $project_timesheet->project->id)}}">{{ Str::limit($project_timesheet->project->name, 25) }}</a>
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </flux:card>
                    @endforeach
                </x-island-card>
            @endif

            {{-- THIS CHECK USER PAID EMPLOYEE TIMESHEETS --}}
            @if(!$employee_weekly_timesheets->isEmpty())
                <x-island-card heading="Employee Paid Timesheets" :separator="true">
                    <x-slot:actions>
                        <flux:button>
                            {{money($employee_weekly_timesheets->flatten(2)->sum('amount'))}}
                        </flux:button>
                    </x-slot:actions>

                    @foreach($employee_weekly_timesheets as $user_id => $employee_timesheet_weeks)
                        <div>
                            <flux:heading size="lg">{{$employee_timesheet_weeks->first()->first()->user->full_name}}</flux:heading>
                        </div>

                        @foreach($employee_timesheet_weeks as $week => $employee_timesheet_week)
                            <flux:card>
                                <div class="flex justify-between">
                                    <flux:heading size="lg">{{'Week of ' . $employee_timesheet_week->first()->date->toFormattedDateString()}}</flux:heading>
                                    <flux:button disabled>
                                        {{ money($employee_timesheet_week->sum('amount')) }}
                                    </flux:button>
                                </div>
                                {{-- <div>
                                    <flux:heading size="lg">{{'Week of ' . $employee_timesheet_week->first()->date->toFormattedDateString()}}</flux:heading>
                                    <flux:separator variant="subtle" />
                                </div> --}}

                                <flux:table>
                                    <flux:table.columns>
                                        <flux:table.column>Amount</flux:table.column>
                                        <flux:table.column>Hours</flux:table.column>
                                        <flux:table.column>Project</flux:table.column>
                                    </flux:table.columns>

                                    <flux:table.rows>
                                        @foreach($employee_timesheet_week as $key => $employee_timesheet_week_project)
                                            <flux:table.row :key="$employee_timesheet_week_project->id">
                                                <flux:table.cell>
                                                    <a wire:navigate.hover href="{{route('timesheets.show', $employee_timesheet_week_project->id)}}">{{ money($employee_timesheet_week_project->amount) }}</a>
                                                </flux:table.cell>
                                                <flux:table.cell>{{ $employee_timesheet_week_project->hours }}</flux:table.cell>
                                                <flux:table.cell>
                                                    <a wire:navigate.hover href="{{route('projects.show', $employee_timesheet_week_project->project->id)}}">{{ $employee_timesheet_week_project->project->name }}</a>
                                                </flux:table.cell>
                                            </flux:table.row>
                                        @endforeach
                                    </flux:table.rows>
                                </flux:table>
                            </flux:card>
                        @endforeach

                        @if(!$loop->last)
                            <flux:separator />
                        @endif
                    @endforeach
                </x-island-card>
            @endif

            {{-- THIS CHECK VENDOR PAID EXPENSES --}}
            {{-- @if(!is_null($vendor_paid_expenses))
            <x-cards.wrapper class="col-span-4 lg:col-span-2 lg:col-start-3">
                <x-cards.heading>
                    <x-slot name="left">
                        <h1>Vendor Paid Expenses</h1>
                    </x-slot>
                </x-cards.heading>

                <x-lists.ul>
                    @foreach($vendor_paid_expenses as $paid_expense)
                        <x-lists.search_li
                            :line_title="money($paid_expense->amount) . ' | ' . $paid_expense->project->name"
                            href="{{route('expenses.show', $paid_expense->id)}}"
                            :bubble_message="'Expense'"
                            >
                        </x-lists.search_li>
                    @endforeach
                </x-lists.ul>
            </x-cards.wrapper>
            @endif --}}

            {{-- EXPENSES --}}
            @if($vendor_expenses->isNotEmpty())
                <div class="col-span-5 lg:col-span-3 lg:col-start-3">
                    <livewire:expenses.expense-index :check="$check->id" :view="'checks.show'"/>
                </div>
            @endif

            {{-- THIS CHECK USER PAID EXPENSES --}}
            @if($user_paid_expenses->isNotEmpty())
                <x-island-card heading="Paid Expenses">
                    <x-slot:actions>
                        <flux:button disabled>
                            {{ money($user_paid_expenses->sum('amount')) }}
                        </flux:button>
                    </x-slot:actions>

                    <div class="space-y-2">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Amount</flux:table.column>
                                <flux:table.column>Date</flux:table.column>
                                <flux:table.column>Vendor</flux:table.column>
                                <flux:table.column>Project</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach ($user_paid_expenses as $expense)
                                    <flux:table.row :key="$expense->id">
                                        <flux:table.cell variant="strong">
                                            <a wire:navigate.hover href="{{route('expenses.show', $expense->id)}}">{{ money($expense->amount) }}</a>
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $expense->date->format('m/d/Y') }}</flux:table.cell>
                                        <flux:table.cell><a wire:navigate.hover href="{{route('vendors.show', $expense->vendor->id)}}">{{Str::limit($expense->vendor->name, 20)}}</a></flux:table.cell>
                                        <flux:table.cell>{{ Str::limit($expense->project->name, 25) }}</flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </x-island-card>
            @endif

            {{-- THIS CHECK DISTRIBUTIONS --}}
            @if($user_distributions->isNotEmpty())
                <x-island-card heading="Paid Distributions">
                    <x-slot:actions>
                        <flux:button disabled>
                            {{ money($user_distributions->sum('amount')) }}
                        </flux:button>
                    </x-slot:actions>

                    <div class="space-y-2">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Amount</flux:table.column>
                                <flux:table.column>Distribution</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach($user_distributions as $user_distribution_expense)
                                    <flux:table.row :key="$user_distribution_expense->id">
                                        <flux:table.cell variant="strong">
                                            <a wire:navigate.hover href="{{route('expenses.show', $user_distribution_expense->id)}}">{{ money($user_distribution_expense->amount) }}</a>
                                        </flux:table.cell>
                                        <flux:table.cell>
                                            @if($user_distribution_expense->distribution)
                                                <a wire:navigate.hover href="{{route('distributions.show', $user_distribution_expense->distribution->id)}}">{{ $user_distribution_expense->distribution->name }}</a>
                                            @else
                                                —
                                            @endif
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </x-island-card>
            @endif

            {{-- THIS CHECK USER PAID REIMBURESEMENT RECEIPTS FROM ANOTHER EMPLOYEE --}}
            @if($user_paid_by_reimbursements->isNotEmpty())
                <x-island-card heading="Paid Off Other User Reimbursements">
                    <x-slot:actions>
                        <flux:button disabled>
                            {{ '-' .  money($user_paid_by_reimbursements->sum('amount')) }}
                        </flux:button>
                    </x-slot:actions>

                    <div class="space-y-2">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Amount</flux:table.column>
                                <flux:table.column>Date</flux:table.column>
                                <flux:table.column>Team Member</flux:table.column>
                                <flux:table.column>Vendor</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach ($user_paid_by_reimbursements as $expense)
                                    <flux:table.row :key="$expense->id">
                                        <flux:table.cell variant="strong">
                                            <a wire:navigate.hover href="{{route('expenses.show', $expense->id)}}">{{ money($expense->amount) }}</a>
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $expense->date->format('m/d/Y') }}</flux:table.cell>
                                        <flux:table.cell>{{ $expense->reimbursment }}</flux:table.cell>
                                        <flux:table.cell><a wire:navigate.hover href="{{route('vendors.show', $expense->vendor->id)}}">{{Str::limit($expense->vendor->name, 20)}}</a></flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </x-island-card>
            @endif

            {{-- USER REIMBURESEMENT (USER OWNS VENDOR) EXPENSES --}}
            @if($user_reimbursement_expenses->isNotEmpty())
                <x-island-card heading="{{ $check->user->first_name }} Paid back these Expenses">
                    <x-slot:actions>
                        <flux:button disabled>
                            -{{ money($user_reimbursement_expenses->sum('amount')) }}
                        </flux:button>
                    </x-slot:actions>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Amount</flux:table.column>
                            <flux:table.column>Vendor</flux:table.column>
                            <flux:table.column>Project</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach($user_reimbursement_expenses as $key => $expense)
                                <flux:table.row :key="$expense->id">
                                    <flux:table.cell variant="strong">
                                        <a wire:navigate.hover href="{{route('expenses.show', $expense->id)}}">{{ money($expense->amount) }}</a>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <a wire:navigate.hover href="{{route('vendors.show', $expense->vendor->id)}}">{{ $expense->vendor->name }}</a>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if($expense->project_id)
                                            <a wire:navigate.hover href="{{route('projects.show', $expense->project->id)}}">{{ Str::limit($expense->project->name, 25) }}</a>
                                        @else
                                            {{ Str::limit($expense->project->name, 25) }}
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </x-island-card>
            @endif
        </div>
    </div>
    <livewire:checks.check-create />
</div>
