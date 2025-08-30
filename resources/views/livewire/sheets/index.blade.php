<div class="max-w-4xl">
    <x-details.card title="Sheet Filters" :accordion="false">
        <x-slot name="details">
            <form id="sheets-form" wire:submit="run">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    {{-- START DATE --}}
                    <div class="col-span-1 sm:col-span-1 lg:col-span-1 min-w-0" wire:key="sheets-start-date">
                        <flux:input
                            wire:model.live.debounce.500ms="start_date"
                            label="Start Date"
                            type="date"
                        />
                    </div>

                    {{-- END DATE --}}
                    <div class="col-span-1 sm:col-span-1 lg:col-span-1 min-w-0" wire:key="sheets-end-date">
                        <flux:input
                            wire:model.live.debounce.500ms="end_date"
                            label="End Date"
                            type="date"
                        />
                    </div>

                    {{-- CASH FILTER --}}
                    <div class="col-span-1 sm:col-span-1 lg:col-span-1 min-w-0">
                        <flux:radio.group wire:model="cash" label="Cash" variant="segmented" class="w-full">
                            <flux:radio value="include" label="Include" />
                            <flux:radio value="hide" label="Hide" />
                        </flux:radio.group>
                    </div>

                    {{-- BANK ACCOUNTS --}}
                    <div class="col-span-1 sm:col-span-1 lg:col-span-1 min-w-0">
                        <div class="space-y-2 ![&_[data-flux-label]]:font-normal">
                            <flux:heading>Bank Accounts</flux:heading>
                            @foreach($this->availableBanks as $bank_id => $bank)
                                <div class="flex items-center gap-2" wire:key="bank-row-{{$bank_id}}">
                                    <flux:checkbox
                                        wire:key="bank-{{$bank_id}}"
                                        name="bank-{{$bank_id}}"
                                        wire:model.live="banks.{{$bank_id}}.checked"
                                    />
                                    <span class="text-sm font-normal text-zinc-800 dark:text-white">{{$bank->name}}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </form>
        </x-slot>
        
        <x-slot name="footer">
            <div x-data x-cloak
                x-show="$wire.start_date && $wire.end_date && Object.values($wire.banks || {}).some(b => b.checked)"
                x-transition.opacity.duration.250ms>
                <flux:button type="submit" form="sheets-form" variant="primary">Show Sheet</flux:button>
            </div>
        </x-slot>
    </x-details.card>
</div>