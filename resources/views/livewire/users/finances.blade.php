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
                    <flux:table.cell>-{{money($user_reimbursement_expenses->sum('amount'))}}</flux:table.cell>
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
                    <flux:table.cell>-{{money($paid_other_user_reimbursements->sum('amount'))}}</flux:table.cell>
                </flux:table.row>
            @endif

            @if($user_reimbursement_paid_by->isNotEmpty())
                <flux:table.row>
                    <flux:table.cell>&emsp; &emsp;User Reimbursement Expenses Paid By Others</flux:table.cell>
                    <flux:table.cell>-{{money($user_reimbursement_paid_by->sum('amount'))}}</flux:table.cell>
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
                        $timesheets_paid->sum('amount')
                        + $distribution_checks->sum('amount')
                        + $timesheets_paid_by->sum('amount')
                        // - $expenses_paid->sum('amount')
                        //  - ($paid_other_user_reimbursements->sum('amount'))
                        )}}
                </flux:table.cell>
            </flux:table.row>

            @if($distribution_expenses->isNotEmpty())
                <flux:table.row>
                    <flux:table.cell>&emsp; Distribution Expenses</flux:table.cell>
                    <flux:table.cell>{{money($distribution_expenses->sum('amount'))}}</flux:table.cell>
                </flux:table.row>
            @endif

            <flux:table.row>
                <flux:table.cell><b>TOTAL FOR USER</b></flux:table.cell>
                <flux:table.cell>
                    {{money(
                        $timesheets_paid->sum('amount')
                        - $expenses_paid->sum('amount')
                        + $distribution_checks->sum('amount')
                        + $distribution_expenses->sum('amount')
                        + $timesheets_paid_by->sum('amount')
                        // - $paid_other_user_reimbursements->sum('amount')
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
