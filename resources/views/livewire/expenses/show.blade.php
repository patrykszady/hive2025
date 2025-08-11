<div>
    <div class="grid max-w-xl grid-cols-4 gap-4 xl:relative lg:max-w-5xl sm:px-6">
        <div class="col-span-4 space-y-4 lg:col-span-2 lg:h-32 lg:sticky lg:top-5">
            {{-- EXPENSE DETAILS --}}
            <x-details.card 
                title="Expense Details"
                subheading="Expense and related details like Expense Splits and Expense Receipts."
                :canEdit="auth()->user()->can('update', $expense)"
            >
                <x-slot:header_buttons>
                    <flux:button.group>
                        <flux:button
                            wire:click="$dispatchTo('expenses.expense-create', 'editExpense', { expense: {{$expense->id}}})"
                            size="sm"
                            >
                            Edit Expense
                        </flux:button>
                        <flux:dropdown position="bottom" align="end">
                            <flux:button icon-trailing="chevron-down" size="sm"></flux:button>
                            <flux:menu>
                                <flux:menu.item wire:click="$dispatchTo('expenses.expenses-associated', 'addAssociatedExpense', { expense: {{$expense->id}}})">Link Expenses</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    </flux:button.group>
                </x-slot:header_buttons>

                <x-slot:details>
                    <x-details.row title="Amount" content="{{ money($expense->amount) }}" />
                    <x-details.row title="Date" content="{{ $expense->date->format('m/d/Y') }}" />
                    <x-details.row 
                        title="Vendor" 
                        content="{{ $expense->vendor->business_name . ', ' . $expense->vendor->business_type }}"
                        href="{{ isset($expense->vendor->id) ? route('vendors.show', $expense->vendor->id) : null }}"
                    />
                    <x-details.row 
                        title="Project" 
                        content="{{ $expense->project->name }}"
                        href="{{ isset($expense->project->id) ? route('projects.show', $expense->project->id) : null }}"
                    />
                    
                    @if($expense->note)
                        <flux:description><i class="text-sky-800">{{$expense->note}}</i></flux:description>
                    @endif
                    @if($expense->has('receipts'))
                        @if(isset($expense->receipts()->first()->notes))
                            <flux:description><i class="text-sky-800">{{$expense->receipts()->first()->notes}}</i></flux:description>
                        @endif
                    @endif

                    @if($expense->reimbursment)
                        <x-details.row title="Reimbursment" content="{{ $expense->reimbursment }}" />
                    @endif

                    @if($expense->paid_by)
                        <x-details.row title="Paid By" content="{{ $expense->paidby->full_name }}" />
                    @endif

                    @if($expense->invoice)
                        <x-details.row title="Invoice" content="{{ $expense->invoice }}" />
                    @endif

                    @if($expense->note)
                        <x-details.row title="Note" content="{{ $expense->note }}" />
                    @endif

                    @if($expense->receipt && $expense->receipt->notes)
                        <x-details.row title="PO" content="{{ $expense->receipt->notes }}" />
                    @endif
                </x-slot:details>

                @if($expense->created_by_user_id === 0)
                    <x-slot:footer>
                        <flux:subheading><i>*Expense Created Automatically.</i></flux:subheading>
                    </x-slot:footer>
                @endif
            </x-details.card>

            {{-- TRANSACTIONS --}}
            @if($expense->transactions->isNotEmpty())
                <x-transactions.list_card 
                    :transactions="$expense->transactions" 
                    :title="$expense->check?->transactions->isNotEmpty() ? 'Check Transactions' : 'Transactions'"
                />
            @endif
        </div>

        <div class="col-span-4 space-y-2 lg:col-span-2">
            {{-- ASSOCIATED EXPENSES --}}
            @if(!is_null($expense->associated_expenses))
                <flux:card class="space-y-2">
                    <flux:heading size="lg" class="mb-0">Linked Expenses</flux:heading>
                    <flux:subheading>Associated Expenses are expenses that are linked to this Expense. For example, a debit from one account and a credit to another. Or a purchase and return expenses that belong together.</flux:subheading>
                    <flux:separator variant="subtle" />

                    <div class="space-y-6">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Amount</flux:table.column>
                                <flux:table.column>Date</flux:table.column>
                                <flux:table.column>Bank</flux:table.column>
                                <flux:table.column>Account</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach ($expense->associated_expenses as $associated_expense)
                                    <flux:table.row :key="$associated_expense->id">
                                        <flux:table.cell variant="strong">
                                            <a href="{{route('expenses.show', $associated_expense->id)}}">
                                                {{ money($associated_expense->amount) }}
                                            </a>
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $associated_expense->date->format('m/d/Y') }}</flux:table.cell>
                                        <flux:table.cell>{{ !$associated_expense->transactions->isEmpty() ? $associated_expense->transactions()->first()->bank_account->bank->name : '' }}</flux:table.cell>
                                        <flux:table.cell>{{ !$associated_expense->transactions->isEmpty() ? $associated_expense->transactions()->first()->bank_account->account_number : '' }}</flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </flux:card>
            @endif

            {{-- SPLITS --}}
            @if(!$expense->splits->isEmpty())
                <flux:card class="space-y-6">
                    <div>
                        <flux:heading size="lg">Splits</flux:heading>
                    </div>

                    <flux:separator variant="subtle" />

                    <div class="space-y-6">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Amount</flux:table.column>
                                <flux:table.column>Project</flux:table.column>
                                <flux:table.column>Reimb.</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach($expense->splits as $split)
                                    <flux:table.row>
                                        <flux:table.cell variant="strong">{{money($split->amount)}}</flux:table.cell>

                                        <flux:table.cell>
                                            @if($split->distribution)
                                                {{$split->distribution->name }}
                                            @else
                                                <a wire:navigate.hover href="{{route('projects.show', $split->project->id)}}">{{ $split->project->address }}</a>
                                            @endif
                                        </flux:table.cell>

                                        <flux:table.cell>{{$split->reimbursment}}</flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </flux:card>
            @endif

            {{-- CHECK --}}
            @if($expense->check)
                <livewire:checks.checks-index :expense_check_id="$expense->check->id" :view="'expenses.show'"/>
            @endif

            {{-- RECEIPTS --}}
            @if(!$expense->receipts->isEmpty())
                <flux:card class="space-y-6">
                    <div class="flex justify-between">
                        <flux:heading size="lg">Receipt</flux:heading>
                        {{-- receipt link button on the right --}}
                        {{-- 10-17-2022..make this a modal --}}
                        @foreach($expense->receipts->whereNotNull('receipt_filename') as $original_receipt)
                            {{-- 09-28-2024 ... if one BUTTON ... if multiple buttton + dropdown on the right  --}}
                            <flux:button
                                href="{{ route('expenses.original_receipt', ['receipts', $original_receipt->receipt_filename]) }}"
                                target="_blank"
                                >
                                View Receipt
                            </flux:button>
                        @endforeach
                    </div>

                    <flux:separator variant="subtle" />

                    <div class="space-y-6">
                        @if($expense->receipts()->latest()->first()->receipt_items == NULL)
                            <div class="flow-root">
                                <div class="m-2">
                                    <pre style="background-color:transparent; overflow: auto;" >{!! $expense->receipts()->latest()->first()->receipt_html !!}</pre>
                                </div>
                            </div>
                        @else
                            @if($expense->receipts()->latest()->first()->receipt_items->items == NULL)
                                <div class="flow-root">
                                    <div class="m-2">
                                        <pre style="background-color:transparent; overflow: auto;" >{!! $expense->receipts()->latest()->first()->receipt_html !!}</pre>
                                    </div>
                                </div>
                            @else
                                @include('livewire.expenses._receipt')
                            @endif
                        @endif
                    </div>
                </flux:card>
            @endif
        </div>
    </div>

	{{-- top level so content is in front of everything on page --}}
    @can('update', $expense)
	    <livewire:expenses.expense-create />
        <livewire:expenses.expenses-associated />
    @endif
</div>
