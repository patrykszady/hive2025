{{--  x-data="{ check_input_existing: @entangle('check_input_existing') }" --}}
<div>
    <x-forms.one_line label="Bank">
        <flux:select wire:model.live="form.bank_account_id" placeholder="Choose bank...">
            <flux:option value="" readonly>Select Bank</flux:option>
            @foreach ($bank_accounts as $bank_account)
                <flux:option value="{{$bank_account->id}}">{{$bank_account->getNameAndType()}}</flux:option>
            @endforeach
        </flux:select>
    </x-forms.one_line>

    <div
        x-data="{ bank_account: @entangle('form.bank_account_id') }"
        x-show="bank_account"
        x-transition
        class="mt-2 space-y-2"
        >
        <x-forms.one_line label="Bank">
            <flux:select wire:model.live="form.check_type" placeholder="Choose payment type...">
                <flux:option value="" readonly>Select Payment Type</flux:option>
                <flux:option value="Check">Check</flux:option>
                <flux:option value="Transfer">Transfer</flux:option>
                <flux:option value="Cash">Cash</flux:option>
            </flux:select>
        </x-forms.one_line>

        <div
            x-data="{ check_type: @entangle('form.check_type') }"
            x-show="check_type == 'Check'"
            x-transition
            >
            <x-forms.one_line label="Check Number">
                <flux:input wire:model.live="form.check_number" type="number" inputmode="numeric" step="1"/>
                <flux:error name="form.check_number" />
                @if($next_check_auto)
                    <flux:description><i class="text-indigo-600">Automatic Next Check Number</i></flux:description>
                @endif
            </x-forms.one_line>
        </div>
    </div>
</div>
