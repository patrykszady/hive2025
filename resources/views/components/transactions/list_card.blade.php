@props([
    'transactions' => [],
    'title' => 'Transactions'
])

@php
    // Column defs live here because this component IS the single source for
    // transaction tables (no Livewire class behind it). Widths sum to 100.
    // Amount and Date never wrap, so they get enough room to hold their widest
    // value in the narrowest card this renders in (the 2-of-5 column on a check
    // page, ~380px): measured 95px and 93px respectively. Bank and Account take
    // what's left and truncate with a tooltip.
    $columns = [
        ['label' => 'Amount', 'width' => 'w-[28%] min-w-0'],
        ['label' => 'Date', 'width' => 'w-[25%] min-w-0'],
        ['label' => 'Bank', 'width' => 'w-[24%] min-w-0'],
        ['label' => 'Account', 'width' => 'w-[23%] min-w-0'],
    ];

    // Auto-detect if we should show vendor information
    $hasVendorInfo = $transactions->contains(function($transaction) {
        return $transaction->vendor && $transaction->vendor->name != 'No Vendor';
    });
    
    // Only show merchant descriptions if at least one transaction has one
    $hasMerchantDescriptions = $transactions->contains(function($transaction) {
        return !empty($transaction->plaid_merchant_description) || !empty($transaction->plaid_merchant_name);
    });
@endphp

{{-- Shared index-table card: fixed column widths so a long bank/account name
     truncates instead of scrolling the card sideways. --}}
<x-index-table :heading="$title">
    <x-index-table.table :columns="$columns">
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
                        <flux:table.cell variant="strong" class="whitespace-nowrap">
                            {{ money($transaction->amount) }}
                        </flux:table.cell>
                        <flux:table.cell class="whitespace-nowrap">{{ $transaction->transaction_date->format('m/d/Y') }}</flux:table.cell>
                        <flux:table.cell class="min-w-0">
                            <x-table-link :label="$transaction->bank_account->bank->name" />
                        </flux:table.cell>
                        <flux:table.cell class="min-w-0">
                            <x-table-link :label="(string) ($transaction->owner ?? $transaction->bank_account->account_number)" />
                        </flux:table.cell>
                    </flux:table.row>
                    
                    {{-- Only show this row if there's vendor info or merchant descriptions to display --}}
                    @if(!empty($displayLines))
                        {{-- Sub-row: belongs to the transaction above — no hover highlight, tucked under it --}}
                        <flux:table.row class="table-subrow">
                            <flux:table.cell :colspan="count($columns)" class="whitespace-normal break-words !border-t-0 !pt-0">
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
    </x-index-table.table>

    {{ $slot ?? '' }}
</x-index-table>