<div class="w-full space-y-4">
    <x-island-card heading="Transaction Accounts" subheading="Connect your Transactions to automatically match and organize with Expenses and Receipts.">
        <x-slot:actions>
            <flux:button wire:navigate.hover wire:click="plaid_link_token" size="sm" icon="plus">New Bank Account</flux:button>
        </x-slot:actions>
    </x-island-card>

    @foreach($this->banks as $bank)
        <livewire:banks.bank-show :bank="$bank" wire:key="{{$bank->id}}" />
    @endforeach
</div>
