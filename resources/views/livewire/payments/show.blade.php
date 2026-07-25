<div>
    <div class="grid max-w-xl grid-cols-4 gap-4 lg:max-w-5xl sm:px-6">
        <div class="col-span-4 lg:col-span-2 space-y-4">
            {{-- PAYMENT DETAILS --}}
            <x-details.card
                title="Payment Details"
                :canEdit="auth()->user()->can('update', $payment)"
                >
                <x-slot:header_buttons>
                    <flux:button
                        size="sm"
                        wire:click="$dispatchTo('payments.payment-create', 'editPayment', { payment: {{ $payment->id }} })"
                        >
                        Edit Payment
                    </flux:button>
                </x-slot:header_buttons>

                <x-slot:details>
                    {{-- Add payment details here --}}
                </x-slot:details>
            </x-details.card>

            {{-- PAYMENT TRANSACTION --}}
            @if($payment->transaction)
            <x-island-card heading="Payment Transaction" :separator="true">

                    <div class="card-flush-bottom space-y-6">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Amount</flux:table.column>
                                <flux:table.column>Date</flux:table.column>
                                <flux:table.column>Bank</flux:table.column>
                                <flux:table.column>Account</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                <flux:table.row :key="$payment->transaction->id">
                                    <flux:table.cell variant="strong">
                                        {{ money($payment->transaction->amount) }}
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $payment->transaction->transaction_date->format('m/d/Y') }}</flux:table.cell>
                                    <flux:table.cell>{{ $payment->transaction->bank_account->bank->name }}</flux:table.cell>
                                    <flux:table.cell>{{ $payment->transaction->owner ?? $payment->transaction->bank_account->account_number }}</flux:table.cell>
                                </flux:table.row>
                                <flux:table.row>
                                    <flux:table.cell colspan="4" class="whitespace-normal break-words">
                                        {{ $payment->transaction->plaid_merchant_description }}
                                    </flux:table.cell>
                                </flux:table.row>
                            </flux:table.rows>
                        </flux:table>
                    </div>
            </x-island-card>
            @endif
        </div>

        <div class="col-span-4 space-y-4 lg:col-span-2 lg:col-start-3">
            {{-- CHECKS DEPOSITED --}}
            <x-island-card heading="Checks Deposited with this payment" :separator="true">
                <x-slot:actions>
                    <flux:button>
                        {{ money($payment->payments->sum('amount')) }}
                    </flux:button>
                </x-slot:actions>

                @foreach($payment->grouped_transaction_payments as $group)
                    <flux:card>
                        <div class="flex justify-between">
                            <flux:heading size="lg">
                                <a wire:navigate.hover 
                                    href="{{ route('clients.show', $group['client']->id) }}" 
                                    wire:navigate.hover
                                    >
                                    {{ $group['client']->name }}
                                </a>
                            </flux:heading>
                            <flux:button disabled>
                                {{ money($group['totalAmount']) }}
                            </flux:button>
                        </div>

                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Amount</flux:table.column>
                                <flux:table.column>Date</flux:table.column>
                                <flux:table.column>Reference</flux:table.column>
                                <flux:table.column>Project</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach($group['payments'] as $paymentItem)
                                    <flux:table.row>
                                        <flux:table.cell>{{ money($paymentItem->amount) }}</flux:table.cell>
                                        <flux:table.cell>{{ $paymentItem->date->format('m/d/Y') }}</flux:table.cell>
                                        <flux:table.cell>{{ $paymentItem->reference }}</flux:table.cell>
                                        <flux:table.cell>
                                            @if($paymentItem->project)
                                                <a wire:navigate.hover 
                                                    href="{{ route('projects.show', $paymentItem->project->id) }}" 
                                                    wire:navigate.hover
                                                    >
                                                    {{ $paymentItem->project->project_name }}
                                                </a>
                                            @elseif($paymentItem->distribution)
                                                {{ $paymentItem->distribution->name }}
                                            @else
                                                —
                                            @endif
                                        </flux:table.cell>                                    
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </flux:card>
                @endforeach
            </x-island-card>
        </div>    
    </div>
    <livewire:payments.payment-create />
</div>


