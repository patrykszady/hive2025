<x-page.shell
    :cols="5"
    :breadcrumbs="[
        ['label' => 'Checks', 'href' => route('checks.index')],
        ['label' => trim(money($check->amount).' · '.($check->owner ?? 'Check'), ' ·')],
    ]"
>
    <x-page.column :span="2" class="lg:sticky lg:top-5">
        {{-- CHECK DETAILS --}}
        <x-details.card title="Details" :canEdit="auth()->user()->can('update', $check)">
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
                {{-- payeeUrl() mirrors owner()'s branching (via-vendor before
                     user), so the label and the link can't point apart. --}}
                <x-details.row title="Payee" :content="$check->owner" :href="$check->payeeUrl()" />
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
    </x-page.column>

    <x-page.column :span="3">
        {{-- USER TIMESHEETS — one card holding every week this check settled,
             each week a collapsible group (same shape as lien-waiver draws). --}}
        @if($weekly_timesheets->isNotEmpty())
            <x-details.card
                title="{{ $check->user->nickname ?: $check->user->first_name }}'s Timesheets"
                :separator="false"
            >
                <x-slot:details>
                    <div class="space-y-2">
                        @foreach($weekly_timesheets->groupBy('date') as $week)
                            @include('livewire.checks._timesheet_week', [
                                'week' => $week,
                                'heading' => 'Week of '.$week->first()->date->startOfWeek()->toFormattedDateString(),
                                'href' => route('timesheets.show', $week->first()),
                            ])
                        @endforeach
                    </div>
                </x-slot:details>
            </x-details.card>
        @endif

        {{-- TIMESHEETS THIS CHECK'S PAYEE PAID FOR OTHER TEAM MEMBERS — one
             card, one group per team member's week. --}}
        @if($employee_weekly_timesheets->isNotEmpty())
            <x-details.card title="Employee Paid Timesheets" :separator="false">
                <x-slot:details>
                    <div class="space-y-2">
                        @foreach($employee_weekly_timesheets as $employee_timesheet_weeks)
                            @foreach($employee_timesheet_weeks as $week)
                                @include('livewire.checks._timesheet_week', [
                                    'week' => $week,
                                    'heading' => $week->first()->user->full_name
                                        .' · Week of '.$week->first()->date->toFormattedDateString(),
                                    'href' => route('timesheets.show', $week->first()),
                                ])
                            @endforeach
                        @endforeach
                    </div>
                </x-slot:details>
            </x-details.card>
        @endif

        {{-- EXPENSES --}}
        @if($vendor_expenses->isNotEmpty())
            <livewire:expenses.expense-index :check="$check->id" :view="'checks.show'"/>
        @endif

        {{-- VENDOR REIMBURSEMENTS DEDUCTED FROM THIS CHECK --}}
        @if($vendor_reimbursement_expenses->isNotEmpty())
            <x-index-table heading="{{ $check->vendor?->name }} Paid back these Expenses">
                <x-slot:badge>
                    <flux:badge color="red" size="sm" inset="top bottom">-{{ money($vendor_reimbursement_expenses->sum('amount')) }}</flux:badge>
                </x-slot:badge>

                <x-index-table.table :columns="\App\Livewire\Checks\CheckShow::expenseColumnDefs()">
                    @foreach($vendor_reimbursement_expenses as $expense)
                        <flux:table.row :key="$expense->id">
                            <flux:table.cell variant="strong" class="whitespace-nowrap">
                                <x-table-link :href="route('expenses.show', $expense->id)" :label="money($expense->amount)" />
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">{{ $expense->date->format('m/d/Y') }}</flux:table.cell>
                            <flux:table.cell class="min-w-0">
                                <x-table-link :href="route('vendors.show', $expense->vendor->id)" :label="$expense->vendor->name" />
                            </flux:table.cell>
                            <flux:table.cell class="min-w-0">
                                <x-table-link
                                    :href="$expense->project_id ? route('projects.show', $expense->project_id) : null"
                                    :label="$expense->project?->name ?? '—'"
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </x-index-table.table>
            </x-index-table>
        @endif

        {{-- THIS CHECK USER PAID EXPENSES --}}
        @if($user_paid_expenses->isNotEmpty())
            <x-index-table heading="Paid Expenses">
                <x-slot:badge>
                    <flux:badge color="zinc" size="sm" inset="top bottom">{{ money($user_paid_expenses->sum('amount')) }}</flux:badge>
                </x-slot:badge>

                <x-index-table.table :columns="\App\Livewire\Checks\CheckShow::expenseColumnDefs()">
                    @foreach ($user_paid_expenses as $expense)
                        <flux:table.row :key="$expense->id">
                            <flux:table.cell variant="strong" class="whitespace-nowrap">
                                <x-table-link :href="route('expenses.show', $expense->id)" :label="money($expense->amount)" />
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">{{ $expense->date->format('m/d/Y') }}</flux:table.cell>
                            <flux:table.cell class="min-w-0">
                                <x-table-link :href="route('vendors.show', $expense->vendor->id)" :label="$expense->vendor->name" />
                            </flux:table.cell>
                            <flux:table.cell class="min-w-0">
                                <x-table-link
                                    :href="$expense->project_id ? route('projects.show', $expense->project_id) : null"
                                    :label="$expense->project?->name ?? '—'"
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </x-index-table.table>
            </x-index-table>
        @endif

        {{-- THIS CHECK DISTRIBUTIONS --}}
        @if($user_distributions->isNotEmpty())
            <x-index-table heading="Paid Distributions">
                <x-slot:badge>
                    <flux:badge color="zinc" size="sm" inset="top bottom">{{ money($user_distributions->sum('amount')) }}</flux:badge>
                </x-slot:badge>

                <x-index-table.table :columns="\App\Livewire\Checks\CheckShow::distributionColumnDefs()">
                    @foreach($user_distributions as $user_distribution_expense)
                        <flux:table.row :key="$user_distribution_expense->id">
                            <flux:table.cell variant="strong" class="whitespace-nowrap">
                                <x-table-link :href="route('expenses.show', $user_distribution_expense->id)" :label="money($user_distribution_expense->amount)" />
                            </flux:table.cell>
                            <flux:table.cell class="min-w-0">
                                <x-table-link
                                    :href="$user_distribution_expense->distribution ? route('distributions.show', $user_distribution_expense->distribution->id) : null"
                                    :label="$user_distribution_expense->distribution?->name ?? '—'"
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </x-index-table.table>
            </x-index-table>
        @endif

        {{-- THIS CHECK USER PAID REIMBURSEMENT RECEIPTS FROM ANOTHER EMPLOYEE --}}
        @if($user_paid_by_reimbursements->isNotEmpty())
            <x-index-table heading="Paid Off Other User Reimbursements">
                <x-slot:badge>
                    <flux:badge color="red" size="sm" inset="top bottom">-{{ money($user_paid_by_reimbursements->sum('amount')) }}</flux:badge>
                </x-slot:badge>

                <x-index-table.table :columns="\App\Livewire\Checks\CheckShow::reimbursementColumnDefs()">
                    @foreach ($user_paid_by_reimbursements as $expense)
                        <flux:table.row :key="$expense->id">
                            <flux:table.cell variant="strong" class="whitespace-nowrap">
                                <x-table-link :href="route('expenses.show', $expense->id)" :label="money($expense->amount)" />
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">{{ $expense->date->format('m/d/Y') }}</flux:table.cell>
                            <flux:table.cell class="min-w-0">
                                <x-table-link :label="$expense->reimbursment" />
                            </flux:table.cell>
                            <flux:table.cell class="min-w-0">
                                <x-table-link :href="route('vendors.show', $expense->vendor->id)" :label="$expense->vendor->name" />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </x-index-table.table>
            </x-index-table>
        @endif

        {{-- USER REIMBURSEMENT (USER OWNS VENDOR) EXPENSES --}}
        @if($user_reimbursement_expenses->isNotEmpty())
            <x-index-table heading="{{ $check->user->first_name }} Paid back these Expenses">
                <x-slot:badge>
                    <flux:badge color="red" size="sm" inset="top bottom">-{{ money($user_reimbursement_expenses->sum('amount')) }}</flux:badge>
                </x-slot:badge>

                <x-index-table.table :columns="\App\Livewire\Checks\CheckShow::paidBackColumnDefs()">
                    @foreach($user_reimbursement_expenses as $expense)
                        <flux:table.row :key="$expense->id">
                            <flux:table.cell variant="strong" class="whitespace-nowrap">
                                <x-table-link :href="route('expenses.show', $expense->id)" :label="money($expense->amount)" />
                            </flux:table.cell>
                            <flux:table.cell class="min-w-0">
                                <x-table-link :href="route('vendors.show', $expense->vendor->id)" :label="$expense->vendor->name" />
                            </flux:table.cell>
                            <flux:table.cell class="min-w-0">
                                <x-table-link
                                    :href="$expense->project_id ? route('projects.show', $expense->project_id) : null"
                                    :label="$expense->project?->name ?? '—'"
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </x-index-table.table>
            </x-index-table>
        @endif

        {{-- SCANNED CHECK IMAGE --}}
        @if($check_images->isNotEmpty())
            <x-details.card :expanded="false" details_text="Details">
                <x-slot:header_buttons>
                    <flux:button
                        :href="route('expenses.original_receipt', ['checks', $check_images->first()->image_filename])"
                        target="_blank"
                        size="sm"
                        variant="ghost"
                        icon="eye"
                        inset="top bottom"
                        title="View Full Size"
                        aria-label="View Full Size"
                    />
                    @if($check_images->first()->statement_filename)
                        <flux:button
                            :href="route('checks.statement_pdf', $check_images->first()->statement_filename)"
                            target="_blank"
                            size="sm"
                            variant="ghost"
                            icon="document-text"
                            inset="top bottom"
                            title="View Full Statement ({{ $check_images->first()->statement_filename }})"
                            aria-label="View Full Statement"
                        />
                    @endif
                </x-slot:header_buttons>

                {{-- Always-visible image(s) — open full size via the header eye icon --}}
                <div class="space-y-3 py-2">
                    @foreach($check_images as $image)
                        <img
                            src="{{ route('expenses.original_receipt', ['checks', $image->image_filename]) }}"
                            alt="Check {{ $image->check_number }}"
                            class="w-full rounded-lg border border-zinc-200 bg-white dark:border-zinc-700"
                            loading="lazy"
                        />
                    @endforeach
                </div>

                <x-slot:details>
                    @foreach($check_images as $image)
                        @php
                            $fields = $image->check_fields ?? [];
                            $numbers = collect([
                                'On Check' => $fields['CheckNumber']['OnCheck'] ?? null,
                                'MICR' => $fields['CheckNumber']['Micr'] ?? null,
                                'Bank' => $fields['CheckNumber']['Bank'] ?? null,
                            ])->filter();
                            $writtenDate = $fields['Date']['OnCheck'] ?? null;
                            $amountOnCheck = $fields['Amount']['OnCheck'] ?? null;
                        @endphp

                        {{-- Check number: one row when all sources agree, separate rows when they differ --}}
                        @if($numbers->unique()->count() > 1)
                            @foreach($numbers as $source => $number)
                                <x-details.row title="Check Number ({{ $source }})" :content="(string) $number" />
                            @endforeach
                        @elseif($image->check_number)
                            <x-details.row title="Check Number" :content="(string) $image->check_number" />
                        @endif

                        @php
                            $resolvedPayee = $image->payeeUser?->full_name ?? $image->payeeVendor?->business_name;
                            $payeeHref = $image->payee_user_id
                                ? route('users.show', $image->payee_user_id)
                                : ($image->payee_vendor_id ? route('vendors.show', $image->payee_vendor_id) : null);
                        @endphp
                        @if($resolvedPayee || $image->payee)
                            <x-details.row
                                title="Payee"
                                :content="$resolvedPayee ?? $image->payee"
                                :href="$payeeHref"
                                :navigate="(bool) $payeeHref"
                                :secondary_content="$resolvedPayee && $resolvedPayee !== $image->payee ? $image->payee : null"
                            />
                        @endif
                        @if(!empty($fields['Memo']))
                            <x-details.row title="Memo" :content="implode(', ', $fields['Memo'])" />
                        @endif

                        @if($image->amount !== null)
                            <x-details.row title="Amount" :content="money($image->amount)" />
                        @endif
                        @if($amountOnCheck && (float) str_replace(',', '', $amountOnCheck) !== (float) $image->amount)
                            <x-details.row title="Amount On Check" :content="'$' . $amountOnCheck" />
                        @endif
                        @if(!empty($fields['AmountWords']))
                            <x-details.row title="Amount In Words" :content="$fields['AmountWords']" />
                        @endif

                        @if($writtenDate)
                            <x-details.row title="Written" :content="\Carbon\Carbon::parse($writtenDate)->format('m/d/Y')" />
                        @endif
                        @if($image->check_date)
                            <x-details.row title="Cleared" :content="$image->check_date->format('m/d/Y')" />
                        @endif

                        @if(!empty($fields['CheckInfo']['PayerName']))
                            <x-details.row title="Payer" :content="$fields['CheckInfo']['PayerName']" />
                        @endif
                        @if(!empty($fields['CheckInfo']['PayerAddress']))
                            <x-details.row title="Payer Address" :content="$fields['CheckInfo']['PayerAddress']" />
                        @endif
                        @if(!empty($fields['CheckInfo']['PayerPhone']))
                            <x-details.row title="Payer Phone" :content="$fields['CheckInfo']['PayerPhone']" />
                        @endif

                        @if(!empty($fields['BankInfo']['BankName']))
                            <x-details.row title="Bank" :content="$fields['BankInfo']['BankName']" />
                        @endif
                        @if(!empty($fields['BankInfo']['RoutingNumber']))
                            <x-details.row title="Routing Number" :content="$fields['BankInfo']['RoutingNumber']" />
                        @endif
                        @if(!empty($fields['BankInfo']['AccountNumber']))
                            <x-details.row title="Account Number" :content="$fields['BankInfo']['AccountNumber']" />
                        @endif

                    @endforeach
                </x-slot:details>
            </x-details.card>
        @endif
    </x-page.column>

    {{-- Modal host: outside the grid so it can't add a phantom gap. --}}
    <x-slot:offstage>
        <livewire:checks.check-create />
    </x-slot:offstage>
</x-page.shell>
