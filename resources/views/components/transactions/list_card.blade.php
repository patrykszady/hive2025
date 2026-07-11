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
        return !empty($transaction->plaid_merchant_description) || !empty($transaction->plaid_merchant_name);
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
                    @php
                        $displayLines = [];
                        $seenNormalized = [];

                        $pushLine = function (?string $value, string $variant = 'default') use (&$displayLines, &$seenNormalized) {
                            $text = trim((string) $value);
                            if ($text === '') {
                                return;
                            }

                            $normalized = strtolower(preg_replace('/\s+/', ' ', $text));
                            if ($normalized === '' || isset($seenNormalized[$normalized])) {
                                return;
                            }

                            $seenNormalized[$normalized] = true;
                            $displayLines[] = [
                                'text' => $text,
                                'variant' => $variant,
                            ];
                        };

                        if ($hasVendorInfo && $transaction->vendor && $transaction->vendor->name != 'No Vendor') {
                            $pushLine($transaction->vendor->name, 'vendor');
                        }

                        $pushLine($transaction->plaid_merchant_name, 'merchant');
                        $pushLine($transaction->plaid_merchant_description, 'description');
                    @endphp

                    <flux:table.row :key="$transaction->id">
                        <flux:table.cell variant="strong">
                            {{ money($transaction->amount) }}
                        </flux:table.cell>
                        <flux:table.cell>{{ $transaction->transaction_date->format('m/d/Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $transaction->bank_account->bank->name }}</flux:table.cell>
                        <flux:table.cell>{{ $transaction->owner ?? $transaction->bank_account->account_number }}</flux:table.cell>
                    </flux:table.row>
                    
                    {{-- Only show this row if there's vendor info or merchant descriptions to display --}}
                    @if(!empty($displayLines))
                        {{-- Sub-row: belongs to the transaction above — no hover highlight, tucked under it --}}
                        <flux:table.row class="table-subrow">
                            <flux:table.cell colspan="4" class="whitespace-normal break-words !border-t-0 !pt-0">
                                @foreach($displayLines as $line)
                                    <span class="block
                                        @if($line['variant'] === 'vendor') font-medium
                                        @elseif($line['variant'] === 'description') italic text-sm text-gray-500
                                        @else text-sm
                                        @endif
                                    ">{{ $line['text'] }}</span>
                                @endforeach
                            </flux:table.cell>
                        </flux:table.row>
                    @endif
                @endforeach
            </flux:table.rows>
        </flux:table>

        {{ $slot ?? '' }}
    </div>
</x-island-card>