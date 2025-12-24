<div class="w-full space-y-4">
    <flux:card>
        <div class="flex justify-between">
            <flux:heading size="lg">Transaction Accounts</flux:heading>
            <flux:button wire:navigate.hover wire:click="plaid_link_token" size="sm" icon="plus">New Bank Account</flux:button>
        </div>
        <flux:subheading size="md">Connect your Transactions to automatically match and organize with Expenses and Receipts.</flux:subheading>
    </flux:card>

    @foreach($this->banks as $bank)
        <livewire:banks.bank-show :bank="$bank" wire:key="{{$bank->id}}" />
    @endforeach
</div>
