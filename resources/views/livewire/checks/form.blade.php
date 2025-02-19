<div x-data="{ transaction: @entangle('form.transaction') }">
    <flux:select x-bind:disabled="transaction" label="Bank" wire:model.live="form.bank_account_id" placeholder="Choose Bank...">
        <flux:select.option value="" readonly>Select Bank...</flux:select.option>
        @foreach($bank_accounts as $bank_account)
            <flux:select.option value="{{$bank_account->id}}">{{$bank_account->getNameAndType()}}</flux:select.option>
        @endforeach
    </flux:select>
    <div
        x-data="{ bank_account: @entangle('form.bank_account_id') }"
        x-show="bank_account"
        x-transition
        class="mt-2 space-y-2"
        >
        <flux:select x-bind:disabled="transaction" label="Type" wire:model.live="form.check_type" placeholder="Choose Payment Type...">
            <flux:select.option value="" readonly>Select Type...</flux:select.option>
            <flux:select.option value="Check">Check</flux:select.option>
            <flux:select.option value="Transfer">Transfer</flux:select.option>
            <flux:select.option value="Cash">Cash</flux:select.option>
        </flux:select>

        <div
            x-data="{ check_type: @entangle('form.check_type') }"
            x-show="check_type == 'Check'"
            x-transition
            >
            <flux:input
                wire:model.live.debounce.500ms="form.check_number"
                x-bind:disabled="transaction"
                label="Check Number"
                type="number"
                size="lg"
                inputmode="numeric"
                pattern="[0-9]*"
                step="1"
                placeholder="1234"
            />
        </div>
    </div>
</div>
