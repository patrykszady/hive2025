<div class="max-w-3xl">
    <flux:card class="space-y-2 mb-4">
        <div class="flex justify-between">
            <flux:heading size="lg">Sheet Filters</flux:heading>
        </div>

        <flux:separator variant="subtle" />

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            {{-- START DATE --}}
            <flux:input
                wire:model.live="start_date"
                label="Start Date"
                type="date"
            />

            {{-- END DATE --}}
            <flux:input
                wire:model.live="end_date"
                label="End Date"
                type="date"
            />

            {{-- BANK ACCOUNT --}}
            <flux:checkbox.group label="Bank Accounts">
                @foreach($banks as $bank_id => $bank)
                    <flux:checkbox wire:model.live="banks.{{$bank_id}}.checked" label="{{$bank->name}}" value="{{$bank_id}}" checked />
                @endforeach
            </flux:checkbox.group>
        </div>
        <div class="flex space-x-2">
            <flux:spacer />

            <flux:button type="button" wire:click="run" variant="primary">Show Sheet</flux:button>
        </div>
    </flux:card>
</div>
