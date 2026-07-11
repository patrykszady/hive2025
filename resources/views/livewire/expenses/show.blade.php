<div>
    <div class="grid max-w-xl grid-cols-4 gap-4 xl:relative lg:max-w-5xl sm:px-6">
    <div class="col-span-4 space-y-4 lg:col-span-2 lg:sticky lg:top-5">
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
                                @php
                                    $linkedChecks = $expense->checks->isNotEmpty()
                                        ? $expense->checks
                                        : collect();
                                    if ($expense->check) {
                                        $linkedChecks = $linkedChecks->concat(collect([$expense->check]));
                                    }
                                    $linkedChecks = $linkedChecks->unique('id');
                                @endphp
                                @if($linkedChecks->isNotEmpty())
                                    <flux:menu.separator />
                                    @foreach($linkedChecks as $check)
                                        <flux:menu.item
                                            x-on:click.stop="if (confirm('Remove this expense from the check?')) { $wire.removeFromCheck({{ $check->id }}) }"
                                        >
                                            Remove from Check {{ $check->check_number ?? $check->id }}
                                        </flux:menu.item>
                                    @endforeach
                                @endif
                            </flux:menu>
                        </flux:dropdown>
                    </flux:button.group>
                </x-slot:header_buttons>

                <x-slot:details>
                    <x-details.row title="Amount" content="{{ money($expense->amount) }}" />
                    <x-details.row title="Date" content="{{ $expense->date->format('m/d/Y') }}" />
                    <x-details.row 
                        title="Vendor" 
                        {{-- . ', ' . $expense->vendor->business_type  --}}
                        content="{{ $expense->vendor->business_name }}"
                        href="{{ isset($expense->vendor->id) ? route('vendors.show', $expense->vendor->id) : null }}"
                    />

                    @if($expense->belongs_to_client_id)
                        @php $belongsToClient = \App\Models\Client::find($expense->belongs_to_client_id); @endphp
                        @if($belongsToClient)
                            <x-details.row 
                                title="Belongs To" 
                                content="{{ $belongsToClient->name }}"
                                href="{{ route('clients.show', $belongsToClient->id) }}"
                            />
                        @endif
                    @elseif($expense->belongs_to_vendor_id && $expense->belongs_to_vendor_id !== auth()->user()->vendor->id)
                        @php $belongsToVendor = \App\Models\Vendor::find($expense->belongs_to_vendor_id); @endphp
                        @if($belongsToVendor)
                            <x-details.row 
                                title="Belongs To" 
                                content="{{ $belongsToVendor->business_name }}"
                                href="{{ route('vendors.show', $belongsToVendor->id) }}"
                            />
                        @endif
                    @endif
                    
                    <x-details.row 
                        title="Project" 
                        content="{{ $expense->project->name }}"
                        secondary_content="{{ $this->notesSummary }}"
                        href="{{ isset($expense->project->id) ? route('projects.show', $expense->project->id) : null }}"
                    />

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

            {{-- CHECKS --}}
            @if($expense->checks->isNotEmpty())
                <livewire:checks.checks-index 
                    :check_ids="$expense->checks->pluck('id')->toArray()" 
                    :view="'expenses.show'" />
            @elseif($expense->check)
                <livewire:checks.checks-index 
                    :expense_check_id="$expense->check->id" 
                    :view="'expenses.show'" />
            @endif

            {{-- TRANSACTIONS (uses allTransactions() fallback: own -> check) --}}
            @php
                $all_transactions = $expense->allTransactions();
                $transaction_word = $all_transactions->count() === 1 ? 'Transaction' : 'Transactions';
            @endphp
            @if($all_transactions->isNotEmpty())
                <x-transactions.list_card
                    :transactions="$all_transactions"
                    :title="$expense->transactions()->exists() ? $transaction_word : (($expense->checks()->exists() || $expense->check?->transactions()->exists()) ? 'Check ' . $transaction_word : $transaction_word)"
                />
            @endif
        </div>

        <div class="col-span-4 space-y-2 lg:col-span-2">
            {{-- ASSOCIATED EXPENSES --}}
            @if(!is_null($expense->associated_expenses))
                <x-island-card heading="Linked Expenses" :separator="true" subheading="Associated Expenses are expenses that are linked to this Expense. For example, a debit from one account and a credit to another. Or a purchase and return expenses that belong together.">

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
                                            <a href="{{route('expenses.show', $associated_expense->id)}}" wire:navigate>
                                                {{ money($associated_expense->amount) }}
                                            </a>
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $associated_expense->date->format('m/d/Y') }}</flux:table.cell>
                                        <flux:table.cell>{{ $associated_expense->allTransactions()->isNotEmpty() ? $associated_expense->allTransactions()->first()->bank_account->bank->name : '' }}</flux:table.cell>
                                        <flux:table.cell>{{ $associated_expense->allTransactions()->isNotEmpty() ? $associated_expense->allTransactions()->first()->bank_account->account_number : '' }}</flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </x-island-card>
            @endif

            {{-- CHECK ASSOCIATED EXPENSES (other expenses paid by the same check) --}}
            @if($check_associated_expenses->isNotEmpty())
                <x-island-card heading="Associated Expenses" :separator="true" subheading="Other expenses paid by the same check.">
                    <div class="space-y-6">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Amount</flux:table.column>
                                <flux:table.column>Date</flux:table.column>
                                <flux:table.column>Vendor</flux:table.column>
                                <flux:table.column>Project</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach ($check_associated_expenses as $associated)
                                    <flux:table.row :key="'check-assoc-' . $associated->id">
                                        <flux:table.cell variant="strong">
                                            <a href="{{ route('expenses.show', $associated->id) }}" wire:navigate>
                                                {{ money($associated->amount) }}
                                            </a>
                                        </flux:table.cell>
                                        <flux:table.cell>{{ $associated->date?->format('m/d/Y') }}</flux:table.cell>
                                        <flux:table.cell>{{ $associated->vendor?->name }}</flux:table.cell>
                                        <flux:table.cell>
                                            @if($associated->project_id && $associated->project)
                                                <a href="{{ route('projects.show', $associated->project_id) }}" wire:navigate>
                                                    {{ Str::limit($associated->project->name, 25) }}
                                                </a>
                                            @else
                                                {{ $associated->project ? Str::limit($associated->project->name, 25) : '' }}
                                            @endif
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </x-island-card>
            @endif

            {{-- SPLITS --}}
            @if($expense->splits()->exists())
                <x-details.card 
                    title="Expense Splits"
                    subheading="How this expense is divided across different projects"
                    details_text="Split Details"
                >
                    <x-slot:details>
                        <div>
                            <flux:table class="w-full">
                                <flux:table.columns>
                                    @if($this->hasReceiptLineItems)
                                        <flux:table.column class="w-[20%]" title="Highlight this Split receipt items below">Items</flux:table.column>
                                        <flux:table.column class="w-[20%]">Amount</flux:table.column>
                                    @else
                                        <flux:table.column>Amount</flux:table.column>
                                    @endif
                                    <flux:table.column class="w-[35%]">Project</flux:table.column>
                                </flux:table.columns>
                                
                                <flux:table.rows>
                                    @foreach($expense->splits as $split)
                                        <flux:table.row :class="$selectedSplitId == $split->id ? 'bg-indigo-50 dark:bg-indigo-900/10' : ''">
                                            @if($this->hasReceiptLineItems)
                                                <flux:table.cell class="!pl-5 pr-5">
                                                    <flux:button 
                                                        wire:click="toggleSplit({{ $split->id }})"
                                                        class="w-full justify-center"
                                                        size="xs"
                                                        :loading="false"
                                                        :variant="$selectedSplitId == $split->id ? 'primary' : 'outline'"
                                                        :color="$selectedSplitId == $split->id ? 'green' : null"
                                                        aria-label="Toggle split selection"
                                                    >
                                                        {{ $selectedSplitId == $split->id ? 'Selected' : 'Select' }}
                                                    </flux:button>
                                                </flux:table.cell>
                                                <flux:table.cell variant="strong">
                                                    <div class="flex items-center gap-1">
                                                        {{money($split->amount)}}
                                                        @if($split->reimbursment && $split->reimbursment !== 'None')
                                                            <flux:badge size="sm" variant="outline" inset="top bottom" color="zinc" title="{{ $split->reimbursment }}">R</flux:badge>
                                                        @endif
                                                    </div>
                                                </flux:table.cell>
                                            @else
                                                <flux:table.cell variant="strong" class="!pl-5 pr-5">
                                                    <div class="flex items-center gap-1">
                                                        {{money($split->amount)}}
                                                        @if($split->reimbursment && $split->reimbursment !== 'None')
                                                            <flux:badge size="sm" variant="outline" inset="top bottom" color="zinc" title="{{ $split->reimbursment }}">R</flux:badge>
                                                        @endif
                                                    </div>
                                                </flux:table.cell>
                                            @endif
                                            
                                            <flux:table.cell class="truncate">
                                                @if($split->distribution)
                                                    {{$split->distribution->name }}
                                                @elseif($split->project)
                                                    <a wire:navigate.hover href="{{route('projects.show', $split->project->id)}}" title="{{ $split->project->name }}">{{ $split->project->name }}</a>
                                                @else
                                                    No Project
                                                @endif
                                            </flux:table.cell>

                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    </x-slot:details>
                </x-details.card>
            @endif

            {{-- RECEIPTS --}}
            @if($expense->receipts()->exists())
                <x-details.card 
                    title="Receipts"
                    subheading="Receipt details and information"
                    :details_text="$this->hasReceiptLineItems ? 'Receipt Items' : false"
                >
                    @if($expense->receipts->count() === 1 && !empty($expense->receipts->first()->receipt_filename))
                        <x-slot:header_buttons>
                            <a
                                href="{{ route('expenses.original_receipt', ['receipts', $expense->receipts->first()->receipt_filename]) }}"
                                target="_blank"
                                title="View Receipt"
                                aria-label="View Receipt"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-md text-zinc-500 hover:text-zinc-700 hover:bg-zinc-950/5 dark:hover:bg-white/10"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4">
                                    <path d="M12 5c-7 0-10 7-10 7s3 7 10 7 10-7 10-7-3-7-10-7zm0 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/>
                                    <circle cx="12" cy="12" r="2.5"/>
                                </svg>
                            </a>
                        </x-slot:header_buttons>
                    @endif
                    

                    <x-slot:details>
                        @if($expense->receipts->count() > 1)
                            {{-- Show tabs for multiple receipts (newest first) --}}
                            @php $orderedReceipts = $expense->receipts->sortByDesc('id')->values(); @endphp
                            <flux:tab.group>
                                <flux:tabs>
                                    @foreach($orderedReceipts as $receipt)
                                        <flux:tab :name="$receipt->id" class="group">
                                            <span class="inline-flex items-center gap-2">
                                                <span>Receipt {{ $loop->iteration }}</span>
                        @if(!empty($receipt->receipt_filename))
                                                    <a
                                                        href="{{ route('expenses.original_receipt', ['receipts', $receipt->receipt_filename]) }}"
                                                        target="_blank"
                                                        title="View Receipt"
                                                        aria-label="View Receipt"
                            class="hidden group-aria-selected:inline-flex items-center text-zinc-500 hover:text-zinc-700"
                                                    >
                                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4">
                                                            <path d="M12 5c-7 0-10 7-10 7s3 7 10 7 10-7 10-7-3-7-10-7zm0 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/>
                                                            <circle cx="12" cy="12" r="2.5"/>
                                                        </svg>
                                                    </a>
                                                @endif
                                            </span>
                                        </flux:tab>
                                    @endforeach
                                </flux:tabs>
                                @foreach($orderedReceipts as $receipt)
                                    <flux:tab.panel :name="$receipt->id" class="!pt-2">
                                            <x-expenses.receipt 
                                                :receipt="$receipt" 
                                                :selectedSplit="$this->selectedSplit" 
                                                :expenseMismatch="$this->expenseMismatch" 
                                                :expenseAmount="$this->expenseAmount"
                                                :compactNotes="false"
                                                :showNotes="false"
                                            />
                                    </flux:tab.panel>
                                @endforeach
                            </flux:tab.group>
                        @else
                            {{-- Show single receipt without tabs --}}
                                <x-expenses.receipt 
                                    :receipt="$expense->receipts->first()" 
                                    :selectedSplit="$this->selectedSplit" 
                                    :expenseMismatch="$this->expenseMismatch" 
                                    :expenseAmount="$this->expenseAmount"
                                    :compactNotes="false"
                                    :showNotes="false"
                                />
                        @endif
                    </x-slot:details>
                </x-details.card>
            @endif
        </div>
    </div>

	{{-- top level so content is in front of everything on page --}}
    @can('update', $expense)
        <livewire:expenses.expense-create />
        <livewire:expenses.expenses-associated />
    @endif

    {{-- Item Detail Modal --}}
    <flux:modal wire:model.self="showItemModal" class="md:min-w-lg">
        <x-expenses.item-detail-modal-content :item="$selectedItem" />
    </flux:modal>
</div>
