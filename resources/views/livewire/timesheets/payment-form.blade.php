<div class="max-w-4xl">
    <form wire:submit="{{$view_text['form_submit']}}">
        <div class="grid max-w-xl grid-cols-5 gap-4 xl:relative lg:max-w-5xl sm:px-6">
            <div class="col-span-5 space-y-4 lg:col-span-2 lg:h-32 lg:sticky lg:top-5">
                <flux:card>
                    <flux:heading size="lg">{{$form->payee_name}} Payment</flux:heading>
                    <flux:subheading><i>Create a Payment for {{$form->payee_name}}</i></flux:subheading>
                    <flux:separator variant="subtle" />
                    <x-cards.body :class="'space-y-2 my-2'">
                        {{-- FORM --}}

                        {{-- PAYEE --}}
                        <x-forms.one_line label="Payee">
                            <flux:input wire:model.live="form.payee_name" type="text" disabled />
                            <flux:error name="form.payee_name" />
                        </x-forms.one_line>

                        @include('livewire.checks._payment_form')
                    </x-cards.body>

                    <flux:separator variant="subtle" />

                    <div class="space-y-2 mt-2">
                        <flux:button class="w-full">Check Total | <b>{{money($this->weekly_timesheets_total)}}</b></flux:button>
                        <flux:button type="submit" variant="primary" class="w-full">{{$view_text['button_text']}}</flux:button>
                    </div>

                    <flux:error name="weekly_timesheets_total" />
                </flux:card>
            </div>
            <div class="col-span-5 space-y-2 lg:col-span-3 lg:col-start-3">
                {{-- USER UNPAID TIMESHEETS --}}
                @if(!$weekly_timesheets->isEmpty())
                    <flux:card class="space-y-2">
                        <div>
                            <flux:heading size="lg"><b>{{$user->first_name}}</b>'s Timesheets</flux:heading>
                        </div>

                        @foreach($weekly_timesheets->groupBy('date') as $weekly_project_timesheets)
                            <flux:card>
                                <div class="flex justify-between">
                                    <flux:heading size="lg">{{'Week of ' . $weekly_project_timesheets->first()->date->startOfWeek()->toFormattedDateString()}}</flux:heading>
                                    <flux:button disabled>
                                        {{ money($weekly_project_timesheets->where('checkbox', true)->sum('amount')) }}
                                    </flux:button>
                                </div>

                                <flux:table>
                                    <flux:columns>
                                        <flux:column></flux:column>
                                        <flux:column>Amount</flux:column>
                                        <flux:column>Hours</flux:column>
                                        <flux:column>Project</flux:column>
                                    </flux:columns>

                                    <flux:rows>
                                        @foreach($weekly_project_timesheets as $timesheet_id => $project_timesheet)
                                            <flux:row :key="$project_timesheet->id">
                                                <flux:cell>
                                                    <flux:checkbox
                                                        wire:model.live="weekly_timesheets.{{$project_timesheet->id}}.checkbox"
                                                    />
                                                </flux:cell>
                                                <flux:cell variant="strong">
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

                {{-- USER PAID (OTHER) EMPLOYEE TIMESHEETS --}}
                @if(!$employee_weekly_timesheets->isEmpty())
                    <flux:card class="space-y-2">
                        <div>
                            <flux:heading size="lg"><b>{{ $user->first_name }}</b> Paid Timesheets</flux:heading>
                        </div>

                        @foreach($this->employee_weekly_timesheets->groupBy('date') as $week_key => $weekly_project_timesheets)
                            <flux:card>
                                <div class="flex justify-between">
                                    <flux:heading size="lg">{{'Week of ' . $weekly_project_timesheets->first()->date->startOfWeek()->toFormattedDateString()}}</flux:heading>
                                    <flux:button disabled>
                                        {{ money($weekly_project_timesheets->where('checkbox', true)->sum('amount')) }}
                                    </flux:button>
                                </div>

                                <flux:table>
                                    <flux:columns>
                                        <flux:column></flux:column>
                                        <flux:column>Amount</flux:column>
                                        <flux:column>User</flux:column>
                                        <flux:column>Hours</flux:column>
                                        <flux:column>Project</flux:column>
                                    </flux:columns>

                                    <flux:rows>
                                        @foreach($weekly_project_timesheets as $timesheet_id => $project_timesheet)
                                            <flux:row :key="$project_timesheet->id">
                                                <flux:cell>
                                                    <flux:checkbox
                                                        wire:model.live="employee_weekly_timesheets.{{$project_timesheet->id}}.checkbox"
                                                    />
                                                </flux:cell>
                                                <flux:cell variant="strong">
                                                    <a wire:navigate.hover href="{{route('timesheets.show', $project_timesheet->id)}}">{{ money($project_timesheet->amount) }}</a>
                                                </flux:cell>
                                                <flux:cell>{{ $project_timesheet->user->first_name }}</flux:cell>
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

                {{-- USER PAID FOR EXPENSES --}}
                @if(!$user_paid_expenses->isEmpty())
                    <flux:card>
                        <div class="flex justify-between">
                            <flux:heading size="lg">{{ $user->first_name }}</b> Paid For Expenses</flux:heading>
                            <flux:button disabled>
                                {{ money($user_paid_expenses->where('checkbox', true)->sum('amount')) }}
                            </flux:button>
                        </div>

                        <flux:table>
                            <flux:columns>
                                <flux:column></flux:column>
                                <flux:column>Amount</flux:column>
                                <flux:column>Vendor</flux:column>
                                <flux:column>Project</flux:column>
                            </flux:columns>

                            <flux:rows>
                                @foreach($user_paid_expenses as $key => $expense)
                                    <flux:row :key="$expense->id">
                                        <flux:cell>
                                            <flux:checkbox
                                                wire:model.live="user_paid_expenses.{{$expense->id}}.checkbox"
                                            />
                                        </flux:cell>
                                        <flux:cell variant="strong">
                                            <a wire:navigate.hover href="{{route('expenses.show', $expense->id)}}">{{ money($expense->amount) }}</a>
                                        </flux:cell>
                                        <flux:cell>
                                            <a wire:navigate.hover href="{{route('vendors.show', $expense->vendor->id)}}">{{ $expense->vendor->name }}</a>
                                        </flux:cell>
                                        <flux:cell>
                                            @if($expense->project_id)
                                                <a wire:navigate.hover href="{{route('projects.show', $expense->project->id)}}">{{ Str::limit($expense->project->name, 25) }}</a>
                                            @else
                                                {{ Str::limit($expense->project->name, 25) }}
                                            @endif
                                        </flux:cell>
                                    </flux:row>
                                @endforeach
                            </flux:rows>
                        </flux:table>
                    </flux:card>
                @endif

                {{-- USER REIMBURESEMENT EXPENSES --}}
                {{-- @if(!$user_reimbursement_expenses->isEmpty())
                    <flux:card class="space-y-2">
                        <div>
                            <flux:heading size="lg"><b>{{ $user->first_name }}</b> Paid Timesheets</flux:heading>
                        </div>

                        @foreach($this->employee_weekly_timesheets->groupBy('date') as $week_key => $weekly_project_timesheets)
                            <flux:card>
                                <div class="flex justify-between">
                                    <flux:heading size="lg">{{'Week of ' . $weekly_project_timesheets->first()->date->startOfWeek()->toFormattedDateString()}}</flux:heading>
                                    <flux:button disabled>
                                        {{ money($weekly_project_timesheets->where('checkbox', true)->sum('amount')) }}
                                    </flux:button>
                                </div>

                                <flux:table>
                                    <flux:columns>
                                        <flux:column></flux:column>
                                        <flux:column>Amount</flux:column>
                                        <flux:column>User</flux:column>
                                        <flux:column>Hours</flux:column>
                                        <flux:column>Project</flux:column>
                                    </flux:columns>

                                    <flux:rows>
                                        @foreach($weekly_project_timesheets as $timesheet_id => $project_timesheet)
                                            <flux:row :key="$project_timesheet->id">
                                                <flux:cell>
                                                    <flux:checkbox
                                                        wire:model.live="employee_weekly_timesheets.{{$project_timesheet->id}}.checkbox"
                                                    />
                                                </flux:cell>
                                                <flux:cell variant="strong">
                                                    <a wire:navigate.hover href="{{route('timesheets.show', $project_timesheet->id)}}">{{ money($project_timesheet->amount) }}</a>
                                                </flux:cell>
                                                <flux:cell>{{ $project_timesheet->user->first_name }}</flux:cell>
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
                @endif --}}
            </div>
        </div>
    </form>
</div>
