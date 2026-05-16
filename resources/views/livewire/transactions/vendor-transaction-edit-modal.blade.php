<div>
    <x-form-modal name="vendor_transaction_edit_modal" title="Edit Vendor Transaction">
        <form id="vendor_transaction_edit_modal_form" wire:submit="save" class="space-y-4">
            <flux:input class="align-input" label="Vendor" />

            <flux:input wire:model="desc" label="Description" />

            <flux:select wire:model="deposit_check" label="Deposit/Check">
                @foreach($depositCheckOptions as $value => $label)
                    <flux:select.option :value="(string) $value">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="amount_sign" label="Amount Sign">
                <flux:select.option value="">Any</flux:select.option>
                <flux:select.option value="1">+$ out</flux:select.option>
                <flux:select.option value="2">-$ in</flux:select.option>
            </flux:select>

            <flux:select
                wire:model="plaid_inst_id"
                label="Plaid Institution"
                variant="listbox"
                searchable
                placeholder="No bank"
            >
                <flux:select.option value="">None</flux:select.option>
                @foreach($this->banks as $bank)
                    <flux:select.option :value="(string) $bank->plaid_ins_id">{{ $bank->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="options" label="Options" />
        </form>

        <x-slot name="footer">
            <flux:button variant="danger" wire:click="delete" wire:confirm="Delete this vendor transaction?">Delete</flux:button>
            <flux:spacer />
            <flux:modal.close>
                <flux:button variant="ghost">Cancel</flux:button>
            </flux:modal.close>
            <flux:button type="submit" form="vendor_transaction_edit_modal_form" variant="primary">Save</flux:button>
        </x-slot>
    </x-form-modal>
</div>
