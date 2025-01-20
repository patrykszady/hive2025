<div>
    <div class="grid max-w-xl grid-cols-5 gap-4 xl:relative lg:max-w-5xl sm:px-6">
        <div class="col-span-5 space-y-4 lg:col-span-2 lg:h-32 lg:sticky lg:top-5">
            {{-- CHECK DETAILS --}}
            <x-lists.details_card>
                {{-- HEADING --}}
                <x-slot:heading>
                    <div>
                        <flux:heading size="lg" class="mb-0">Check Details</flux:heading>
                    </div>

                    <flux:button
                        wire:click="$dispatchTo('checks.check-create', 'editCheck', { check: {{$check->id}}})"
                        size="sm"
                        >
                        Edit Check
                    </flux:button>
                </x-slot>

                {{-- DETAILS --}}
                <x-lists.details_list>
                    <x-lists.details_item title="Amount" detail="{{money($check->amount)}}" />
                    <x-lists.details_item title="Payee" detail="{{$check->owner}}" href="{{$check->vendor_id ? route('vendors.show', $check->vendor->id) : ''}}" />
                    <x-lists.details_item title="Date" detail="{{$check->date->format('m/d/Y')}}" />
                    <x-lists.details_item title="Type" detail="{{$check->check_type}}" />

                    @if($check->bank_account)
                        <x-lists.details_item title="Bank" detail="{{$check->bank_account->getNameAndType()}}" />
                    @endif

                    <x-lists.details_item title="{{$check->check_type === 'Check' ? 'Check Number' : ($check->check_type === 'Transfer' ? 'Transfer ID' : ($check->check_type === 'Cash' ? 'Chas ID' : ''))}}" detail="{{$check->check_number}}" />
                </x-lists.details_list>
            </x-lists.details_card>

            {{-- CHECK TRANSACTIONS --}}
            @if(!$check->transactions->isEmpty())
                <flux:card class="space-y-2">
                    <flux:heading size="lg" class="mb-0">Transactions</flux:heading>
                    <flux:separator variant="subtle" />

                    <div class="space-y-6">
                        {{-- wire:loading.class="opacity-50 text-opacity-40" --}}
                        <flux:table>
                            <flux:columns>
                                <flux:column>Amount</flux:column>
                                <flux:column>Date</flux:column>
                                <flux:column>Bank</flux:column>
                                <flux:column>Account</flux:column>
                            </flux:columns>

                            <flux:rows>
                                @foreach ($check->transactions as $transaction)
                                    <flux:row :key="$transaction->id">
                                        <flux:cell variant="strong">
                                            {{ money($transaction->amount) }}
                                        </flux:cell>
                                        <flux:cell>{{ $transaction->transaction_date->format('m/d/Y') }}</flux:cell>
                                        <flux:cell>{{ $transaction->bank_account->bank->name }}</flux:cell>
                                        <flux:cell>{{ $transaction->bank_account->account_number }}</flux:cell>
                                    </flux:row>
                                    <flux:row>
                                        <flux:cell colspan="4" class="text-right"><i>{{ $transaction->plaid_merchant_description }}</i></flux:cell>
                                    </flux:row>
                                @endforeach
                            </flux:rows>
                        </flux:table>
                    </div>
                </flux:card>
            @endif
        </div>

        <div class="col-span-5 space-y-2 lg:col-span-3">
            {{-- THIS CHECK USER PAID TIMESHEETS --}}
            @if(!$weekly_timesheets->isEmpty())
                <flux:card class="space-y-2">
                    <div>
                        <flux:heading size="lg"><b>{{$check->user->first_name}}</b>'s Timesheets</flux:heading>
                    </div>

                    @foreach($weekly_timesheets->groupBy('date') as $weekly_project_timesheets)
                        <flux:card>
                            <div class="flex justify-between">
                                <flux:heading size="lg">{{'Week of ' . $weekly_project_timesheets->first()->date->startOfWeek()->toFormattedDateString()}}</flux:heading>
                                <flux:button disabled>
                                    {{ money($weekly_project_timesheets->sum('amount')) }}
                                </flux:button>
                            </div>

                            <flux:table>
                                <flux:columns>
                                    <flux:column>Amount</flux:column>
                                    <flux:column>Hours</flux:column>
                                    <flux:column>Project</flux:column>
                                </flux:columns>

                                <flux:rows>
                                    @foreach($weekly_project_timesheets as $key => $project_timesheet)
                                        <flux:row :key="$project_timesheet->id">
                                            <flux:cell>
                                                <a wire:navigate.hover href="{{route('timesheets.show', $project_timesheet->id)}}">{{ money($project_timesheet->amount) }}</a>
                                            </flux:cell>
                                            <flux:cell>{{ $project_timesheet->hours }}</flux:cell>
                                            <flux:cell>
                                                <a wire:navigate.hover href="{{route('projects.show', $project_timesheet->project->id)}}">{{ Str::limit($project_timesheet->project->name, 25) }}</a>
                                            </flux:cell>
                                        </flux:row>
                                    @endforeach
                                </flux:rows>
                            </flux:table>
                        </flux:card>
                    @endforeach
                </flux:card>
            @endif

            {{-- THIS CHECK USER PAID EMPLOYEE TIMESHEETS --}}
            @if(!$employee_weekly_timesheets->isEmpty())
                <flux:card class="space-y-2">
                    <div class="flex justify-between">
                        <flux:heading size="lg">Employee Paid Timesheets</flux:heading>
                        {{-- <flux:button>
                            {{$employee_weekly_timesheets->sum('amount')}}
                        </flux:button> --}}
                    </div>

                    <flux:separator />

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
                                    <flux:columns>
                                        <flux:column>Amount</flux:column>
                                        <flux:column>Hours</flux:column>
                                        <flux:column>Project</flux:column>
                                    </flux:columns>

                                    <flux:rows>
                                        @foreach($employee_timesheet_week as $key => $employee_timesheet_week_project)
                                            <flux:row :key="$employee_timesheet_week_project->id">
                                                <flux:cell>
                                                    <a wire:navigate.hover href="{{route('timesheets.show', $employee_timesheet_week_project->id)}}">{{ money($employee_timesheet_week_project->amount) }}</a>
                                                </flux:cell>
                                                <flux:cell>{{ $employee_timesheet_week_project->hours }}</flux:cell>
                                                <flux:cell>
                                                    <a wire:navigate.hover href="{{route('projects.show', $employee_timesheet_week_project->project->id)}}">{{ $employee_timesheet_week_project->project->name }}</a>
                                                </flux:cell>
                                            </flux:row>
                                        @endforeach
                                    </flux:rows>
                                </flux:table>
                            </flux:card>
                        @endforeach

                        @if(!$loop->last)
                            <flux:separator />
                        @endif
                    @endforeach
                </flux:card>
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

            {{-- THIS CHECK EXPENSES EXPENSES --}}
            @if(!$vendor_expenses->isEmpty())
                <div class="col-span-5 lg:col-span-3 lg:col-start-3">
                    <livewire:expenses.expense-index :check="$check->id" :view="'checks.show'"/>
                </div>
            @endif

            {{-- THIS CHECK USER PAID EXPENSES --}}
            @if(!$user_paid_expenses->isEmpty())
                <flux:card class="space-y-2">
                    <div class="flex justify-between">
                        <flux:heading size="lg">Paid Expenses</flux:heading>
                        <flux:button disabled>
                            {{ money($user_paid_expenses->sum('amount')) }}
                        </flux:button>
                    </div>

                    <div class="space-y-2">
                        <flux:table>
                            <flux:columns>
                                <flux:column>Amount</flux:column>
                                <flux:column>Date</flux:column>
                                <flux:column>Vendor</flux:column>
                                <flux:column>Project</flux:column>
                            </flux:columns>

                            <flux:rows>
                                @foreach ($user_paid_expenses as $expense)
                                    <flux:row :key="$expense->id">
                                        <flux:cell variant="strong">
                                            <a wire:navigate.hover href="{{route('expenses.show', $expense->id)}}">{{ money($expense->amount) }}</a>
                                        </flux:cell>
                                        <flux:cell>{{ $expense->date->format('m/d/Y') }}</flux:cell>
                                        <flux:cell><a wire:navigate.hover href="{{route('vendors.show', $expense->vendor->id)}}">{{Str::limit($expense->vendor->name, 20)}}</a></flux:cell>
                                        <flux:cell><a wire:navigate.hover href="{{$expense->project->name == 'EXPENSE SPLIT' ? '' : route('projects.show', $expense->project->id)}}">{{ Str::limit($expense->project->name, 25) }}</a></flux:cell>
                                    </flux:row>
                                @endforeach
                            </flux:rows>
                        </flux:table>
                    </div>
                </flux:card>
            @endif

            {{-- THIS CHECK DISTRIBUTIONS --}}
            @if(!$user_distributions->isEmpty())
                <flux:card class="space-y-2">
                    <div class="flex justify-between">
                        <flux:heading size="lg">Paid Distrbutions</flux:heading>
                        <flux:button disabled>
                            {{ money($user_distributions->sum('amount')) }}
                        </flux:button>
                    </div>

                    <div class="space-y-2">
                        <flux:table>
                            <flux:columns>
                                <flux:column>Amount</flux:column>
                                <flux:column>Distribution</flux:column>
                            </flux:columns>

                            <flux:rows>
                                @foreach($user_distributions as $user_distribution_expense)
                                    <flux:row :key="$user_distribution_expense->id">
                                        <flux:cell variant="strong">
                                            <a wire:navigate.hover href="{{route('expenses.show', $user_distribution_expense->id)}}">{{ money($user_distribution_expense->amount) }}</a>
                                        </flux:cell>
                                        <flux:cell>
                                            <a wire:navigate.hover href="{{route('distributions.show', $user_distribution_expense->distribution->id)}}">{{ $user_distribution_expense->distribution->name }}</a>
                                        </flux:cell>
                                    </flux:row>
                                @endforeach
                            </flux:rows>
                        </flux:table>
                    </div>
                </flux:card>
            @endif

            {{-- THIS CHECK USER PAID REIMBURESEMENT RECEIPTS FROM ANOTHER EMPLOYEE --}}
            {{-- @if(!$user_paid_reimburesements->isEmpty())
            <x-cards class="col-span-4 lg:col-span-2 lg:col-start-3">
                <x-cards.heading>
                    <x-slot name="left">
                        <h1>Paid Employee Reimbursements</h1>
                    </x-slot>
                </x-cards.heading>

                <x-lists.ul>
                    @foreach($user_paid_reimburesements as $user_distribution_expense)
                        <x-lists.search_li
                            :href="route('expenses.show', $user_distribution_expense)"
                            :line_title="money($user_distribution_expense->amount)"
                            :bubble_message="'Reimbursement'"
                            >
                        </x-lists.search_li>
                    @endforeach
                </x-lists.ul>
            </x-cards>
            @endif --}}

            {{-- THIS CHECK USER PAID REIMBURESEMENT RECEIPTS FROM ANOTHER EMPLOYEE --}}
            @if(!$user_paid_by_reimbursements->isEmpty())
                <flux:card class="space-y-2">
                    <div class="flex justify-between">
                        <flux:heading size="lg">Paid Other Employee Reimbursements</flux:heading>
                        <flux:button disabled>
                            {{ '-' .  money($user_paid_by_reimbursements->sum('amount')) }}
                        </flux:button>
                    </div>

                    <flux:separator variant="subtle" />

                    <div class="space-y-2">
                        <flux:table>
                            <flux:columns>
                                <flux:column>Amount</flux:column>
                                {{--  sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')" --}}
                                <flux:column>Date</flux:column>
                                <flux:column>Team Member</flux:column>
                                <flux:column>Vendor</flux:column>
                            </flux:columns>

                            <flux:rows>
                                @foreach ($user_paid_by_reimbursements as $expense)
                                    <flux:row :key="$expense->id">
                                        <flux:cell variant="strong">
                                            <a wire:navigate.hover href="{{route('expenses.show', $expense->id)}}">{{ money($expense->amount) }}</a>
                                        </flux:cell>
                                        <flux:cell>{{ $expense->date->format('m/d/Y') }}</flux:cell>
                                        <flux:cell>{{ $expense->reimbursment }}</flux:cell>
                                        <flux:cell><a wire:navigate.hover href="{{route('vendors.show', $expense->vendor->id)}}">{{Str::limit($expense->vendor->name, 20)}}</a></flux:cell>
                                    </flux:row>
                                @endforeach
                            </flux:rows>
                        </flux:table>
                    </div>
                </flux:card>
            @endif
        </div>
    </div>
    <livewire:checks.check-create />
</div>
