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
                <flux:button
                    type="submit"
                    form="sheets-form"
                    variant="primary"
                    wire:loading.attr="disabled"
                    wire:target="run"
                >
                    <span wire:loading.remove wire:target="run">Show Sheet</span>
                    <span class="inline-flex items-center gap-2" wire:loading wire:target="run">
                        <svg class="size-4 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <span>Loading…</span>
                    </span>
                </flux:button>
            </div>
        </x-slot>
    </x-details.card>
</div>