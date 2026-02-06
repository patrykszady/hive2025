@props([
    'transactions' => [],
    'title' => 'Transactions'
])

@php
    // Auto-detect if we should show vendor information
    $hasVendorInfo = $transactions->contains(function($transaction) {
        return $transaction->vendor && $transaction->vendor->name != 'No Vendor';
    });
    
    // Only show merchant descriptions if at least one transaction has one
    $hasMerchantDescriptions = $transactions->contains(function($transaction) {
        return !empty($transaction->plaid_merchant_description);
    });
@endphp

<x-island-card heading="{{ $title }}" :separator="true">

    <div class="space-y-6">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Amount</flux:table.column>
                <flux:table.column>Date</flux:table.column>
                <flux:table.column>Bank</flux:table.column>
                <flux:table.column>Account</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($transactions as $transaction)
                    <flux:table.row :key="$transaction->id">
                        <flux:table.cell variant="strong">
                            {{ money($transaction->amount) }}
                        </flux:table.cell>
                        <flux:table.cell>{{ $transaction->transaction_date->format('m/d/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $transaction->bank_account->bank->name }}</flux:table.cell>
                        <flux:table.cell>{{ $transaction->owner ?? $transaction->bank_account->account_number }}</flux:table.cell>
                    </flux:table.row>
                    
                    {{-- Only show this row if there's vendor info or merchant descriptions to display --}}
                    @if(($hasVendorInfo && $transaction->vendor && $transaction->vendor->name != 'No Vendor') || !empty($transaction->plaid_merchant_description))
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="whitespace-normal break-words">
                                @if($hasVendorInfo && $transaction->vendor && $transaction->vendor->name != 'No Vendor')
                                    <span class="font-medium block">
                                        {{ $transaction->vendor->name }}
                                    </span>
                                @endif
                                @if($transaction->plaid_merchant_description && 
                                    (!$transaction->vendor || $transaction->plaid_merchant_description !== $transaction->vendor->name))
                                    <span class="block italic text-sm text-gray-500">
                                        {{ $transaction->plaid_merchant_description }}
                                    </span>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endif
                @endforeach
            </flux:table.rows>
        </flux:table>

        {{ $slot ?? '' }}
    </div>
</x-island-card>