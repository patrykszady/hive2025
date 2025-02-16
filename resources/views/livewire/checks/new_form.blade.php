<flux:modal name="check_form_modal" class="space-y-2">
    <form wire:submit="{{$view_text['form_submit']}}" class="grid gap-6">
        <div x-data="{ transaction: @entangle('form.transaction') }">
            <flux:select x-bind:disabled="transaction" label="Bank" wire:model.live="form.bank_account_id" placeholder="Choose Bank...">
                @foreach($bank_accounts as $bank_account)
                    <flux:option value="{{$bank_account->id}}">{{$bank_account->getNameAndType()}}</flux:option>
                @endforeach
            </flux:select>
            <div
                x-data="{ bank_account: @entangle('form.bank_account_id') }"
                x-show="bank_account"
                x-transition
                class="mt-2 space-y-2"
                >
                <flux:select x-bind:disabled="transaction" label="Type" wire:model.live="form.check_type" placeholder="Choose Payment Type...">
                    <flux:option value="" readonly>Select Type...</flux:option>
                    <flux:option value="Check">Check</flux:option>
                    <flux:option value="Transfer">Transfer</flux:option>
                    <flux:option value="Cash">Cash</flux:option>
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
            <div class="flex space-x-2 mt-2">
                <flux:spacer />

                @if(!$form->transaction)
                    <flux:button wire:click="remove" variant="danger">Remove</flux:button>
                @endif
                <flux:button type="submit" x-bind:disabled="transaction" variant="primary">{{$view_text['button_text']}}</flux:button>
            </div>
        </div>
    </form>
</flux:modal>
