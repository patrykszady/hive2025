<div>
    <div class="grid max-w-xl grid-cols-4 gap-4 xl:relative lg:max-w-5xl sm:px-6">
        <div class="col-span-4 space-y-4 lg:col-span-2 lg:h-32 lg:sticky lg:top-5">
            {{-- EXPENSE DETAILS --}}
            <flux:card>
                <div class="flex justify-between">
                    <flux:heading size="lg" class="mb-0">Expense Details</flux:heading>
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
                </div>
                <flux:subheading>Expense and related details like Expense Splits and Expense Receipts.</flux:subheading>

                <flux:separator class="my-2"/>

                <x-lists.details_list>
                    <x-lists.details_item title="Amount" detail="{{money($expense->amount)}}" />
                    <x-lists.details_item title="Date" detail="{{$expense->date->format('m/d/Y')}}" />
                    <x-lists.details_item title="Vendor" detail="{{$expense->vendor->name}}" href="{{isset($expense->vendor->id) ? route('vendors.show', $expense->vendor->id) : ''}}"/>
                    <x-lists.details_item title="Project" detail="{{$expense->project->name}}" href="{{isset($expense->project->id) ? route('projects.show', $expense->project->id) : ''}}"/>

                    @if($expense->reimbursment)
                        <x-lists.details_item title="Reimbursment" detail="{{$expense->reimbursment}}" />
                    @endif

                    @if($expense->paid_by)
                        <x-lists.details_item title="Paid By" detail="{{$expense->paidby->full_name}}" />
                    @endif

                    @if($expense->invoice)
                        <x-lists.details_item title="Invoice" detail="{{$expense->invoice}}" />
                    @endif

                    @if($expense->note)
                        <x-lists.details_item title="Note" detail="{{$expense->note}}" />
                    @endif

                    @if($expense->receipt)
                        @if($expense->receipt->notes)
                            <x-lists.details_item title="PO" detail="{{$expense->receipt->notes}}" />
                        @endif
                    @endif
                </x-lists.details_list>

                {{-- FOOTER --}}
                <div>
                    @if($expense->created_by_user_id === 0)
                        <flux:subheading><i>*Expense Created Automatically.</i></flux:subheading>
                    @endif
                </div>
            </flux:card>

            {{-- TRANSACTIONS --}}
            {{-- 10-01-2024 USE FROM EXPENSES.INDEX @include --}}
            @if(!$expense->transactions->isEmpty())
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
                                @foreach ($expense->transactions as $transaction)
                                    <flux:row :key="$transaction->id">
                                        <flux:cell variant="strong">
                                            {{ money($transaction->amount) }}
                                        </flux:cell>
                                        <flux:cell>{{ $transaction->transaction_date->format('m/d/Y') }}</flux:cell>
                                        <flux:cell>{{ $transaction->bank_account->bank->name }}</flux:cell>
                                        <flux:cell>{{ isset($transaction->owner) ? $transaction->owner : $transaction->bank_account->account_number }}</flux:cell>
                                    </flux:row>
                                    <flux:row>
                                        <flux:cell colspan="4" class="text-right">{{ $transaction->vendor->name != 'No Vendor' ? $transaction->vendor->name : $transaction->plaid_merchant_description}}</flux:cell>
                                    </flux:row>
                                @endforeach
                            </flux:rows>
                        </flux:table>
                    </div>
                </flux:card>
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
                            <flux:columns>
                                <flux:column>Amount</flux:column>
                                <flux:column>Date</flux:column>
                                <flux:column>Bank</flux:column>
                                <flux:column>Account</flux:column>
                            </flux:columns>

                            <flux:rows>
                                @foreach ($expense->associated_expenses as $associated_expense)
                                    <flux:row :key="$associated_expense->id">
                                        <flux:cell variant="strong">
                                            <a href="{{route('expenses.show', $associated_expense->id)}}">
                                                {{ money($associated_expense->amount) }}
                                            </a>
                                        </flux:cell>
                                        <flux:cell>{{ $associated_expense->date->format('m/d/Y') }}</flux:cell>
                                        <flux:cell>{{ !$associated_expense->transactions->isEmpty() ? $associated_expense->transactions()->first()->bank_account->bank->name : '' }}</flux:cell>
                                        <flux:cell>{{ !$associated_expense->transactions->isEmpty() ? $associated_expense->transactions()->first()->bank_account->account_number : '' }}</flux:cell>
                                    </flux:row>
                                @endforeach
                            </flux:rows>
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
                            <flux:columns>
                                <flux:column>Amount</flux:column>
                                <flux:column>Project</flux:column>
                                <flux:column>Reimb.</flux:column>
                            </flux:columns>

                            <flux:rows>
                                @foreach($expense->splits as $split)
                                    <flux:row>
                                        <flux:cell variant="strong">{{money($split->amount)}}</flux:cell>

                                        <flux:cell>
                                            @if($split->distribution)
                                                {{$split->distribution->name }}
                                            @else
                                                <a wire:navigate.hover href="{{route('projects.show', $split->project->id)}}">{{ $split->project->address }}</a>
                                            @endif
                                        </flux:cell>

                                        <flux:cell>{{$split->reimbursment}}</flux:cell>
                                    </flux:row>
                                @endforeach
                            </flux:rows>
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
                                href="{{ route('expenses.original_receipt', $original_receipt->receipt_filename) }}"
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
