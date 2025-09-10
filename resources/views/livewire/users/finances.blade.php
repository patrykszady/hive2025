<div class="space-y-6">
    <flux:card>
        {{-- HEADING --}}
        <div class="flex justify-between">
            <flux:heading size="lg" class="mb-0">User Finances</flux:heading>
        </div>

        <flux:separator variant="subtle" />

        {{-- DETAILS --}}
        <flux:table>
            <flux:table.columns>
                <flux:table.column></flux:table.column>
                <flux:table.column>{{$year}}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                <flux:table.row>
                    <flux:table.cell><b>Checks Written</b></flux:table.cell>
                    <flux:table.cell>{{money($checks_written->sum('amount'))}}</flux:table.cell>
                </flux:table.row>

                <flux:table.row>
                    <flux:table.cell>&emsp; Timesheets Paid</flux:table.cell>
                    <flux:table.cell>{{money($timesheets_paid->sum('amount'))}}</flux:table.cell>
                </flux:table.row>

                @if($user_reimbursement_expenses->isNotEmpty())
                    <flux:table.row>
                        <flux:table.cell>&emsp; &emsp; User Reimbursment Expenses Paid</flux:table.cell>
                        <flux:table.cell>{{money($user_reimbursement_expenses->sum('amount'))}}</flux:table.cell>
                    </flux:table.row>
                @endif

                @if($timesheets_paid_others->isNotEmpty())
                    <flux:table.row>
                        <flux:table.cell>&emsp; Timesheets Paid Others</flux:table.cell>
                        <flux:table.cell>{{money($timesheets_paid_others->sum('amount'))}}</flux:table.cell>
                    </flux:table.row>
                @endif

                @if($timesheets_paid_by->isNotEmpty())
                    <flux:table.row>
                        <flux:table.cell>&emsp; Timesheets Paid By</flux:table.cell>
                        <flux:table.cell>{{money($timesheets_paid_by->sum('amount'))}}</flux:table.cell>
                    </flux:table.row>
                @endif

                @if($paid_other_user_reimbursements->isNotEmpty())
                    <flux:table.row>
                        <flux:table.cell>&emsp; &emsp; Paid Other User Reimbursement Expenses</flux:table.cell>
                        <flux:table.cell>{{money($paid_other_user_reimbursements->sum('amount'))}}</flux:table.cell>
                    </flux:table.row>
                @endif

                @if($user_reimbursement_paid_by->isNotEmpty())
                    <flux:table.row>
                        <flux:table.cell>&emsp; &emsp;User Reimbursement Expenses Paid By Others</flux:table.cell>
                        <flux:table.cell>{{money($user_reimbursement_paid_by->sum('amount'))}}</flux:table.cell>
                    </flux:table.row>
                @endif

                @if($distribution_checks->isNotEmpty())
                    <flux:table.row>
                        <flux:table.cell>&emsp; Distribution Checks</flux:table.cell>
                        <flux:table.cell>{{money($distribution_checks->sum('amount'))}}</flux:table.cell>
                    </flux:table.row>
                @endif

                @if($expenses_paid->isNotEmpty())
                    <flux:table.row>
                        <flux:table.cell>&emsp; Expenses Paid</flux:table.cell>
                        <flux:table.cell>{{money($expenses_paid->sum('amount'))}}</flux:table.cell>
                    </flux:table.row>
                @endif

                <flux:table.row>
                    <flux:table.cell><b>TOTAL CHECKS FOR USER</b></flux:table.cell>
                    <flux:table.cell>
                        {{money(
                                // Definition: Timesheets Paid + Distribution Checks
                                $timesheets_paid->sum('amount')
                                + $distribution_checks->sum('amount')
                                + $timesheets_paid_by->sum('amount')
                            )}}
                    </flux:table.cell>
                </flux:table.row>

                @if($distribution_expenses->isNotEmpty())
                    <flux:table.row>
                        <flux:table.cell>&emsp; Distribution Expenses</flux:table.cell>
                        <flux:table.cell>{{money($distribution_expenses->sum('amount'))}}</flux:table.cell>
                    </flux:table.row>
                @endif

                @if($conflicting_distribution_expenses->isNotEmpty())
                    <flux:table.row>
                        <flux:table.cell colspan="2" class="p-0">
                            <flux:accordion transition>
                                <flux:accordion.item>
                                    <flux:accordion.heading>
                                        <div class="flex justify-between w-full pr-3">
                                            <div class="flex items-center gap-2">
                                                <span class="text-amber-600">Conflicting Distribution Expenses</span>
                                                <flux:badge size="xs" color="yellow" inset="top bottom">{{$conflicting_distribution_expenses->count()}}</flux:badge>
                                            </div>
                                            <div class="text-amber-600 font-medium">{{money($conflicting_distribution_expenses->sum('amount'))}}</div>
                                        </div>
                                    </flux:accordion.heading>
                                    <flux:accordion.content>
                                        <div class="mt-2">
                                            <flux:table>
                                                <flux:table.columns>
                                                    <flux:table.column>Date</flux:table.column>
                                                    <flux:table.column>Expense</flux:table.column>
                                                    <flux:table.column>Check</flux:table.column>
                                                    <flux:table.column class="text-right">Amount</flux:table.column>
                                                </flux:table.columns>
                                                <flux:table.rows>
                                                    @foreach($prepared_conflicting as $c)
                                                        <flux:table.row>
                                                            <flux:table.cell>{{$c['date']}}</flux:table.cell>
                                                            <flux:table.cell>
                                                                <a href="{{$c['expense_link']}}" class="text-primary-600 hover:underline" target="_blank">Expense #{{$c['id']}}</a>
                                                            </flux:table.cell>
                                                            <flux:table.cell>
                                                                @if($c['check_link'])
                                                                    <a href="{{$c['check_link']}}" class="text-primary-600 hover:underline" target="_blank">Check {{$c['check_number'] ?? $c['check_id']}}</a>
                                                                @else
                                                                    <span class="text-slate-400">—</span>
                                                                @endif
                                                            </flux:table.cell>
                                                            <flux:table.cell class="text-right">{{money($c['amount'])}}</flux:table.cell>
                                                        </flux:table.row>
                                                    @endforeach
                                                </flux:table.rows>
                                            </flux:table>
                                        </div>
                                    </flux:accordion.content>
                                </flux:accordion.item>
                            </flux:accordion>
                        </flux:table.cell>
                    </flux:table.row>
                @endif

                <flux:table.row>
                    <flux:table.cell><b>TOTAL FOR USER</b></flux:table.cell>
                    <flux:table.cell>
                        {{money(
                                // Definition: TOTAL CHECKS FOR USER (Timesheets Paid + Distribution Checks) + Distribution Expenses
                                $timesheets_paid->sum('amount')
                                + $distribution_checks->sum('amount')
                                + $timesheets_paid_by->sum('amount')
                                + $distribution_expenses->sum('amount')
                        )}}
                    </flux:table.cell>
                </flux:table.row>

                @if($this->getCheckDifference() != 0)
                    <flux:table.row>
                        <flux:table.cell><i>[Difference]</i></flux:table.cell>
                        <flux:table.cell>{{ money($this->getCheckDifference()) }}</flux:table.cell>
                    </flux:table.row>
                @endif
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
