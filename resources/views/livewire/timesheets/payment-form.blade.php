<div class="max-w-4xl" x-data="{
    initDate() {
        // Only set date if it's empty (new payment)
        if (!$wire.form.date) {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            $wire.form.date = `${year}-${month}-${day}`;
        }
    }
}" x-init="initDate()">
    <form id="payment-form" wire:submit="{{$view_text['form_submit']}}">
        <div class="grid max-w-xl grid-cols-5 gap-4 xl:relative lg:max-w-5xl sm:px-6">
            <div class="col-span-5 space-y-4 lg:col-span-2 lg:h-32 lg:sticky lg:top-5">
                <x-island-card heading="{{$payeeName}} Payment" subheading="Create a Payment for {{$payeeName}}" :separator="true">
                    <x-cards.body :class="'space-y-2 my-2'">
                        {{-- PAYEE --}}
                        <x-forms.one_line label="Payee">
                            <flux:input type="text" disabled value="{{$payeeName}}" />
                        </x-forms.one_line>

                        @can('viewAnyPayment', App\Models\Timesheet::class)
                            @include('livewire.checks._payment_form', ['disablePaidBy' => $this->disablePaidBy])
                        @endcan
                    </x-cards.body>

                    <flux:separator variant="subtle" />

                    <div class="space-y-2 mt-2">
                        <flux:button class="w-full">Check Total | <b>{{money($this->weekly_timesheets_total)}}</b></flux:button>
                        @can('viewAnyPayment', App\Models\Timesheet::class)
                            <flux:button wire:click="confirmPayment" variant="primary" class="w-full">{{$view_text['button_text']}}</flux:button>
                        @endcan
                    </div>

                    <flux:error name="payment_total" />
                </x-island-card>
            </div>
            <div class="col-span-5 space-y-2 lg:col-span-3 lg:col-start-3">
                {{-- USER UNPAID TIMESHEETS --}}
                @if(!$weekly_timesheets->isEmpty())
                    <x-island-card heading="{{ $user->first_name }}'s Timesheets">

                        @foreach($weekly_timesheets->groupBy('date') as $weekly_project_timesheets)
                            <flux:card>
                                <div class="flex justify-between">
                                    <flux:heading size="lg">{{'Week of ' . $weekly_project_timesheets->first()->date->startOfWeek()->toFormattedDateString()}}</flux:heading>
                                    <flux:button disabled>
                                        {{ money($weekly_project_timesheets->filter(fn($t) => ($selectedWeeklyTimesheets[$t->id] ?? false))->sum('amount')) }}
                                    </flux:button>
                                </div>

                                <flux:checkbox.group>
                                    <flux:table>
                                        <flux:table.columns>
                                            <flux:table.column><flux:checkbox.all /></flux:table.column>
                                            <flux:table.column>Amount</flux:table.column>
                                            <flux:table.column>Hours</flux:table.column>
                                            <flux:table.column>Project</flux:table.column>
                                        </flux:table.columns>

                                        <flux:table.rows>
                                            @foreach($weekly_project_timesheets as $timesheet_id => $project_timesheet)
                                                <flux:table.row :key="$project_timesheet->id">
                                                    <flux:table.cell>
                                                        <flux:checkbox wire:model.live="selectedWeeklyTimesheets.{{$project_timesheet->id}}" />
                                                    </flux:table.cell>
                                                    <flux:table.cell variant="strong">
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
                                </flux:checkbox.group>
                            </flux:card>
                        @endforeach
                    </x-island-card>
                @endif

                {{-- USER PAID (OTHER) EMPLOYEE TIMESHEETS --}}
                @if(!$employee_weekly_timesheets->isEmpty())
                    <x-island-card heading="{{ $user->first_name }} Paid Other Timesheets">

                        @foreach($this->employee_weekly_timesheets->groupBy('date') as $week_key => $weekly_project_timesheets)
                            <flux:card>
                                <div class="flex justify-between">
                                    <flux:heading size="lg">{{'Week of ' . $weekly_project_timesheets->first()->date->startOfWeek()->toFormattedDateString()}}</flux:heading>
                                    <flux:button disabled>
                                        {{ money($weekly_project_timesheets->filter(fn($t) => ($selectedEmployeeWeeklyTimesheets[$t->id] ?? false))->sum('amount')) }}
                                    </flux:button>
                                </div>

                                <flux:table>
                                    <flux:table.columns>
                                        <flux:table.column></flux:table.column>
                                        <flux:table.column>Amount</flux:table.column>
                                        <flux:table.column>User</flux:table.column>
                                        <flux:table.column>Hours</flux:table.column>
                                        <flux:table.column>Project</flux:table.column>
                                    </flux:table.columns>

                                    <flux:table.rows>
                                            @foreach($weekly_project_timesheets as $timesheet_id => $project_timesheet)
                                            <flux:table.row :key="$project_timesheet->id">
                                                <flux:table.cell>
                                                    <flux:checkbox wire:model.live="selectedEmployeeWeeklyTimesheets.{{$project_timesheet->id}}" />
                                                </flux:table.cell>
                                                <flux:table.cell variant="strong">
                                                    <a wire:navigate.hover href="{{route('timesheets.show', $project_timesheet->id)}}">{{ money($project_timesheet->amount) }}</a>
                                                </flux:table.cell>
                                                <flux:table.cell>{{ $project_timesheet->user->first_name }}</flux:table.cell>
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

                {{-- USER PAID FOR EXPENSES --}}
                @if(!$user_paid_expenses->isEmpty())
                    <x-island-card heading="{{ $user->first_name }} Paid For Expenses">
                        <x-slot:actions>
                            <flux:button disabled>
                                {{ money($user_paid_expenses->filter(fn($e) => ($selectedUserPaidExpenses[$e->id] ?? false))->sum('amount')) }}
                            </flux:button>
                        </x-slot:actions>

                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column></flux:table.column>
                                <flux:table.column>Amount</flux:table.column>
                                <flux:table.column>Vendor</flux:table.column>
                                <flux:table.column>Project</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach($user_paid_expenses as $key => $expense)
                                    <flux:table.row :key="$expense->id">
                                        <flux:table.cell>
                                            <flux:checkbox wire:model.live="selectedUserPaidExpenses.{{$expense->id}}" />
                                        </flux:table.cell>
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

                {{-- USER REIMBURESEMENT (USER OWNS CASH GS CONSTRUCTION) EXPENSES --}}
                @if(!$user_reimbursement_expenses->isEmpty())
                    <x-island-card heading="{{ $user->first_name }} owns for Expenses">
                        <x-slot:actions>
                            <flux:button disabled>
                                -{{ money($user_reimbursement_expenses->filter(fn($e) => ($selectedUserReimbursementExpenses[$e->id] ?? false))->sum('amount')) }}
                            </flux:button>
                        </x-slot:actions>

                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column></flux:table.column>
                                <flux:table.column>Amount</flux:table.column>
                                <flux:table.column>Vendor</flux:table.column>
                                <flux:table.column>Project</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach($user_reimbursement_expenses as $key => $expense)
                                    <flux:table.row :key="$expense->id">
                                        <flux:table.cell>
                                            <flux:checkbox wire:model.live="selectedUserReimbursementExpenses.{{$expense->id}}" />
                                        </flux:table.cell>
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
    </form>

    {{-- Payment Confirmation Modal --}}
    <flux:modal name="confirm-payment" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Confirm Payment</flux:heading>
                <flux:text class="mt-2">Please verify the check amount before proceeding.</flux:text>
            </div>

            <div class="bg-zinc-100 dark:bg-zinc-800 rounded-lg p-4 text-center">
                <flux:text class="text-sm text-zinc-500">Check Total</flux:text>
                <flux:heading size="xl" class="!mt-1">{{money($this->weekly_timesheets_total)}}</flux:heading>
            </div>

            <div class="flex gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost" class="w-full">Cancel</flux:button>
                </flux:modal.close>
                <flux:button wire:click="save" variant="primary" class="w-full">Confirm & Pay</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
