<div class="max-w-lg space-y-4">
    <x-island-card heading="Transaction Accounts" subheading="Connect your Transactions to automatically match and organize with Expenses and Receipts.">
        <x-slot:actions>
            <flux:button wire:navigate.hover wire:click="plaid_link_token" size="sm" icon="plus">New Bank Account</flux:button>
        </x-slot:actions>
    </x-island-card>

    @foreach($this->banks->filter(fn($bank) => $bank->error !== false) as $bank)
        <flux:callout color="red" icon="exclamation-triangle">
            <flux:callout.heading>{{ $bank->name }}</flux:callout.heading>
            <flux:callout.text>
                <span class="font-semibold">{{ $bank->error['error_code'] }}</span>
                @if(!empty($bank->error['display_message'] ?? $bank->error['error_message'] ?? null))
                    &mdash; {{ $bank->error['display_message'] ?? $bank->error['error_message'] }}
                @endif
            </flux:callout.text>
        </flux:callout>
    @endforeach

    @foreach($this->banks as $bank)
        <livewire:banks.bank-show :bank="$bank" wire:key="{{$bank->id}}" />
    @endforeach
</div>
