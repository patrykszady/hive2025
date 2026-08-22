<div class="space-y-6">
    <x-island-card heading="User Finances" :separator="true">

        {{-- DETAILS: multi-year (2020..current year) --}}
        @php($colspan = count($years) + 1)
        {{-- No overflow wrapper of our own: flux:table ships its own
             scroll area (<ui-table-scroll-area class="overflow-auto">), and
             nesting a second overflow-x-auto around it stacked TWO horizontal
             scrollbars at the bottom of this card. The sticky first column
             pins against Flux's scroll area either way. --}}
        <div class="card-flush-bottom">
        <flux:table>
            <flux:table.columns>
                <flux:table.column class="sticky left-0 z-20 bg-white dark:bg-zinc-800 border-r border-zinc-200 dark:border-zinc-700"></flux:table.column>
                @foreach($years as $yr)
                    <flux:table.column class="border-l border-zinc-200 dark:border-zinc-700">
                        {{ $yr }}@if($yr === $currentYear) (YTD)@endif
                    </flux:table.column>
                @endforeach
            </flux:table.columns>

            <flux:table.rows>
                {{-- Checks Written --}}
                <flux:table.row>
                    <flux:table.cell class="sticky left-0 z-10 bg-white dark:bg-zinc-800 border-r border-zinc-200 dark:border-zinc-700"><b>Checks Written</b></flux:table.cell>
                    @foreach($years as $yr)
                        <flux:table.cell class="border-l border-zinc-200 dark:border-zinc-700">{{ money($sumsByYear[$yr]['checks_written'] ?? 0) }}</flux:table.cell>
                    @endforeach
                </flux:table.row>

                {{-- Timesheets Paid --}}
                <flux:table.row>
                    <flux:table.cell class="sticky left-0 z-10 bg-white dark:bg-zinc-800 border-r border-zinc-200 dark:border-zinc-700">&emsp; Timesheets Paid</flux:table.cell>
                    @foreach($years as $yr)
                        <flux:table.cell class="border-l border-zinc-200 dark:border-zinc-700">{{ money($sumsByYear[$yr]['timesheets_paid'] ?? 0) }}</flux:table.cell>
                    @endforeach
                </flux:table.row>

                {{-- User Reimbursement Expenses Paid (company paid on user's behalf) --}}
                @if(($visibility['user_reimbursement_expenses'] ?? false))
                    <flux:table.row>
                        <flux:table.cell class="sticky left-0 z-10 bg-white dark:bg-zinc-800 border-r border-zinc-200 dark:border-zinc-700">&emsp; &emsp; User Reimbursment Expenses Paid</flux:table.cell>
                        @foreach($years as $yr)
                            <flux:table.cell class="border-l border-zinc-200 dark:border-zinc-700">{{ money($sumsByYear[$yr]['user_reimbursement_expenses'] ?? 0) }}</flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endif

                {{-- Timesheets Paid Others --}}
                @if(($visibility['timesheets_paid_others'] ?? false))
                    <flux:table.row>
                        <flux:table.cell class="sticky left-0 z-10 bg-white dark:bg-zinc-800 border-r border-zinc-200 dark:border-zinc-700">&emsp; Timesheets Paid Others</flux:table.cell>
                        @foreach($years as $yr)
                            <flux:table.cell class="border-l border-zinc-200 dark:border-zinc-700">{{ money($sumsByYear[$yr]['timesheets_paid_others'] ?? 0) }}</flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endif

                {{-- Timesheets Paid By --}}
                @if(($visibility['timesheets_paid_by'] ?? false))
                    <flux:table.row>
                        <flux:table.cell class="sticky left-0 z-10 bg-white dark:bg-zinc-800 border-r border-zinc-200 dark:border-zinc-700">&emsp; Timesheets Paid By</flux:table.cell>
                        @foreach($years as $yr)
                            <flux:table.cell class="border-l border-zinc-200 dark:border-zinc-700">{{ money($sumsByYear[$yr]['timesheets_paid_by'] ?? 0) }}</flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endif

                {{-- Paid Other User Reimbursement Expenses --}}
                @if(($visibility['paid_other_user_reimbursements'] ?? false))
                    <flux:table.row>
                        <flux:table.cell class="sticky left-0 z-10 bg-white dark:bg-zinc-800 border-r border-zinc-200 dark:border-zinc-700">&emsp; &emsp; Paid Other User Reimbursement Expenses</flux:table.cell>
                        @foreach($years as $yr)
                            <flux:table.cell class="border-l border-zinc-200 dark:border-zinc-700">{{ money($sumsByYear[$yr]['paid_other_user_reimbursements'] ?? 0) }}</flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endif

                {{-- User Reimbursement Expenses Paid By Others --}}
                @if(($visibility['user_reimbursement_paid_by'] ?? false))
                    <flux:table.row>
                        <flux:table.cell class="sticky left-0 z-10 bg-white dark:bg-zinc-800 border-r border-zinc-200 dark:border-zinc-700">&emsp; &emsp; User Reimbursement Expenses Paid By Others</flux:table.cell>
                        @foreach($years as $yr)
                            <flux:table.cell class="border-l border-zinc-200 dark:border-zinc-700">{{ money($sumsByYear[$yr]['user_reimbursement_paid_by'] ?? 0) }}</flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endif

                {{-- Distribution Checks --}}
                @if(($visibility['distribution_checks'] ?? false))
                    <flux:table.row>
                        <flux:table.cell class="sticky left-0 z-10 bg-white dark:bg-zinc-800 border-r border-zinc-200 dark:border-zinc-700">&emsp; Distribution Checks</flux:table.cell>
                        @foreach($years as $yr)
                            <flux:table.cell class="border-l border-zinc-200 dark:border-zinc-700">{{ money($sumsByYear[$yr]['distribution_checks'] ?? 0) }}</flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endif

                {{-- Expenses Paid --}}
                @if(($visibility['expenses_paid'] ?? false))
                    <flux:table.row>
                        <flux:table.cell class="sticky left-0 z-10 bg-white dark:bg-zinc-800 border-r border-zinc-200 dark:border-zinc-700">&emsp; Expenses Paid</flux:table.cell>
                        @foreach($years as $yr)
                            <flux:table.cell class="border-l border-zinc-200 dark:border-zinc-700">{{ money($sumsByYear[$yr]['expenses_paid'] ?? 0) }}</flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endif

                {{-- TOTAL CHECKS FOR USER --}}
                <flux:table.row>
                    <flux:table.cell class="sticky left-0 z-10 bg-white dark:bg-zinc-800 border-r border-zinc-200 dark:border-zinc-700"><b>TOTAL CHECKS FOR USER</b></flux:table.cell>
                    @foreach($years as $yr)
                        <flux:table.cell class="border-l border-zinc-200 dark:border-zinc-700">{{ money($sumsByYear[$yr]['total_checks_for_user'] ?? 0) }}</flux:table.cell>
                    @endforeach
                </flux:table.row>

                {{-- Distribution Expenses --}}
                @if(($visibility['distribution_expenses'] ?? false))
                    <flux:table.row>
                        <flux:table.cell class="sticky left-0 z-10 bg-white dark:bg-zinc-800 border-r border-zinc-200 dark:border-zinc-700">&emsp; Distribution Expenses</flux:table.cell>
                        @foreach($years as $yr)
                            <flux:table.cell class="border-l border-zinc-200 dark:border-zinc-700">{{ money($sumsByYear[$yr]['distribution_expenses'] ?? 0) }}</flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endif

                {{-- Conflicting Distribution Expenses (drill-down per year) --}}
                @if(($visibility['conflicting_distribution_expenses'] ?? false))
                    <flux:table.row>
                        <flux:table.cell colspan="{{ $colspan }}" class="p-0">
                            <flux:accordion transition>
                                <flux:accordion.item>
                                    <flux:accordion.heading>
                                        <div class="flex justify-between w-full pr-3">
                                            <div class="flex items-center gap-2">
                                                <span class="text-amber-600">Conflicting Distribution Expenses</span>
                                                @php($totalConflictingCount = collect($years)->sum(fn($yr) => count($prepared_conflicting_by_year[$yr] ?? [])))
                                                <flux:badge size="xs" color="yellow" inset="top bottom">{{ $totalConflictingCount }}</flux:badge>
                                            </div>
                                            @php($totalConflictingAmount = collect($years)->sum(fn($yr) => array_sum(array_map(fn($r) => $r['amount'] ?? 0, $prepared_conflicting_by_year[$yr] ?? []))))
                                            <div class="text-amber-600 font-medium">{{ money($totalConflictingAmount) }}</div>
                                        </div>
                                    </flux:accordion.heading>
                                    <flux:accordion.content>
                                        <div class="mt-2 space-y-6">
                                            @foreach($years as $yr)
                                                @php($rows = $prepared_conflicting_by_year[$yr] ?? [])
                                                @if(count($rows))
                                                    <div>
                                                        <div class="flex items-center justify-between mb-2">
                                                            <div class="flex items-center gap-2">
                                                                <span class="font-medium">{{ $yr }}</span>
                                                                <flux:badge size="xs" color="yellow" inset="top bottom">{{ count($rows) }}</flux:badge>
                                                            </div>
                                                            @php($yrAmount = array_sum(array_map(fn($r) => $r['amount'] ?? 0, $rows)))
                                                            <div class="font-medium">{{ money($yrAmount) }}</div>
                                                        </div>
                                                        <flux:table>
                                                            <flux:table.columns>
                                                                <flux:table.column>Date</flux:table.column>
                                                                <flux:table.column>Expense</flux:table.column>
                                                                <flux:table.column>Check</flux:table.column>
                                                                <flux:table.column class="text-right">Amount</flux:table.column>
                                                            </flux:table.columns>
                                                            <flux:table.rows>
                                                                @foreach($rows as $c)
                                                                    <flux:table.row>
                                                                        <flux:table.cell>{{ $c['date'] }}</flux:table.cell>
                                                                        <flux:table.cell>
                                                                            <a href="{{ $c['expense_link'] }}" class="text-primary-600 hover:underline" target="_blank">Expense #{{ $c['id'] }}</a>
                                                                        </flux:table.cell>
                                                                        <flux:table.cell>
                                                                            @if($c['check_link'])
                                                                                <a href="{{ $c['check_link'] }}" class="text-primary-600 hover:underline" target="_blank">Check {{ $c['check_number'] ?? $c['check_id'] }}</a>
                                                                            @else
                                                                                <span class="text-slate-400">—</span>
                                                                            @endif
                                                                        </flux:table.cell>
                                                                        <flux:table.cell class="text-right">{{ money($c['amount']) }}</flux:table.cell>
                                                                    </flux:table.row>
                                                                @endforeach
                                                            </flux:table.rows>
                                                        </flux:table>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    </flux:accordion.content>
                                </flux:accordion.item>
                            </flux:accordion>
                        </flux:table.cell>
                    </flux:table.row>
                @endif

                {{-- TOTAL FOR USER --}}
                <flux:table.row>
                    <flux:table.cell class="sticky left-0 z-10 bg-white dark:bg-zinc-800 border-r border-zinc-200 dark:border-zinc-700"><b>TOTAL FOR USER</b></flux:table.cell>
                    @foreach($years as $yr)
                        <flux:table.cell class="border-l border-zinc-200 dark:border-zinc-700">{{ money($sumsByYear[$yr]['total_for_user'] ?? 0) }}</flux:table.cell>
                    @endforeach
                </flux:table.row>

                {{-- Difference --}}
                @php($anyDiff = collect($years)->reduce(fn($carry, $yr) => $carry || (($sumsByYear[$yr]['difference'] ?? 0.0) != 0.0), false))
                @if($anyDiff)
                    <flux:table.row>
                        <flux:table.cell class="sticky left-0 z-10 bg-white dark:bg-zinc-800 border-r border-zinc-200 dark:border-zinc-700"><i>[Difference]</i></flux:table.cell>
                        @foreach($years as $yr)
                            <flux:table.cell class="border-l border-zinc-200 dark:border-zinc-700">{{ money($sumsByYear[$yr]['difference'] ?? 0) }}</flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endif
            </flux:table.rows>
        </flux:table>
        </div>
    </x-island-card>
</div>
