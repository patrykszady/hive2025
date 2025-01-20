<flux:card class="space-y-2 mb-4">
    <div class="flex justify-between">
        <flux:heading size="lg">Audit Report</flux:heading>
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="audit_submit">

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
            {{-- END DATE --}}
            <flux:input
                wire:model.live="end_date"
                label="End Date"
                type="date"
            />

            {{-- BANK ACCOUNT --}}
            <flux:checkbox.group label="Bank Accounts">
                @foreach($banks as $bank_id => $bank)
                    <flux:checkbox wire:model.live="banks.{{$bank_id}}.checked" label="{{$bank->name}}" value="{{$bank_id}}" />
                @endforeach
            </flux:checkbox.group>

            {{-- TYPE --}}
            <flux:radio.group wire:model="type" label="Audit Type" variant="segmented">
                <flux:radio value="general" label="General" />
                <flux:radio value="workers" label="Workers" />
            </flux:radio.group>
        </div>

        <flux:separator variant="subtle" class="my-2" />

        <div class="flex space-x-2">
            <flux:spacer />

            <flux:button type="submit" variant="primary">Run Audit</flux:button>
        </div>
    </form>
</flux:card>
