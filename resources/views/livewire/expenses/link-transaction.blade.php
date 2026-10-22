<div>
    <x-island-card heading="Bank Transaction">
        <x-slot:actions>
            <flux:button size="sm" icon="link" wire:click="openPicker">Link bank transaction</flux:button>
        </x-slot:actions>

        {{-- A card the company has never been charged on may be someone else's:
             marking it counts its payments as paid, on every expense it paid.
             Under the header, so the heading keeps its room. --}}
        <x-slot:subheading_actions>
            @foreach ($this->outsideCardCandidates as $lastFour)
                <flux:button size="sm" variant="danger" icon="no-symbol" wire:key="outside-card-candidate-{{ $lastFour }}" data-outside-card-candidate="{{ $lastFour }}" wire:click="markOutsideCard('{{ $lastFour }}')" wire:confirm="Card ••{{ $lastFour }} is not the company's? Every expense it paid counts as paid by someone else.">••{{ $lastFour }} {{ \App\Models\OutsideCard::labelFor((int) $expense->belongs_to_vendor_id) }}</flux:button>
            @endforeach
        </x-slot:subheading_actions>
    </x-island-card>

    <flux:modal name="link-transaction" class="md:w-[48rem]">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Link a bank transaction</flux:heading>
                <flux:text class="mt-1">Unlinked charges from {{ \App\Livewire\Expenses\LinkTransaction::WINDOW_DAYS }} days before to {{ \App\Livewire\Expenses\LinkTransaction::WINDOW_DAYS }} days after {{ $expense->date?->format('m/d/Y') }}, this vendor's at any amount, other vendors' only at the exact amount. Expense amount: ${{ number_format((float) $expense->amount, 2) }}.</flux:text>
            </div>

            @if ($open)
                @if ($this->candidates->isEmpty())
                    <flux:text>No unlinked charges near this date for this vendor or this amount.</flux:text>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Date</flux:table.column>
                            <flux:table.column>Account</flux:table.column>
                            <flux:table.column>Vendor</flux:table.column>
                            <flux:table.column>Description</flux:table.column>
                            <flux:table.column align="end">Amount</flux:table.column>
                            <flux:table.column align="end">Difference</flux:table.column>
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->candidates as $transaction)
                                @php
                                    $difference = round((float) $transaction->amount - (float) $expense->amount, 2);
                                @endphp
                                <flux:table.row wire:key="link-candidate-{{ $transaction->id }}">
                                    <flux:table.cell>{{ $transaction->transaction_date?->format('m/d/Y') }}</flux:table.cell>
                                    <flux:table.cell>{{ $transaction->bank_account?->bank?->name }} {{ $transaction->bank_account?->account_number }}</flux:table.cell>
                                    <flux:table.cell class="max-w-[10rem] truncate" data-candidate-vendor>{{ $transaction->vendor_id ? $transaction->vendor?->name : '—' }}</flux:table.cell>
                                    <flux:table.cell class="max-w-[16rem] truncate">{{ $transaction->plaid_merchant_name ?: $transaction->plaid_merchant_description }}</flux:table.cell>
                                    <flux:table.cell align="end">${{ number_format((float) $transaction->amount, 2) }}</flux:table.cell>
                                    <flux:table.cell align="end">
                                        @if ($difference == 0)
                                            <flux:badge size="sm" color="green">Exact</flux:badge>
                                        @else
                                            {{ $difference > 0 ? '+' : '−' }}${{ number_format(abs($difference), 2) }}
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell align="end">
                                        <flux:button size="xs" variant="primary" wire:click="link({{ $transaction->id }})">Link</flux:button>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            @endif
        </div>
    </flux:modal>
</div>
