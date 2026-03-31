<div class="max-w-3xl">
    @if($view === NULL)
        {{-- Mobile: accordion collapsed by default --}}
        <flux:card class="!px-5 !py-2 mb-4 sm:hidden">
            <flux:accordion transition>
                <flux:accordion.item>
                    <flux:accordion.heading>
                        <flux:heading size="lg">Filters</flux:heading>
                    </flux:accordion.heading>
                    <flux:accordion.content>
                        <div class="flex flex-col gap-4">
                            <flux:input wire:model.debounce.500ms.live="amount" label="Amount" icon="magnifying-glass" placeholder="123.45" />
                            <flux:input wire:model.debounce.500ms.live="check_number" label="Check Number" icon="magnifying-glass" placeholder="1234" />
                            <flux:select wire:model.live="bank" label="Bank" placeholder="Select Bank..." variant="listbox" placeholder="Choose Bank...">
                                <flux:select.option value="">All Banks</flux:select.option>
                                @foreach ($banks->groupBy('plaid_ins_id') as $bank)
                                    <flux:select.option value="{{$bank->first()->id}}">{{$bank->first()->name}}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    </flux:accordion.content>
                </flux:accordion.item>
            </flux:accordion>
        </flux:card>

        {{-- Desktop: always expanded --}}
        <x-island-card heading="Filters" :separator="true" class="mb-4 hidden sm:block">
            <div class="flex flex-row items-end gap-4">
                <div class="flex-1 min-w-0">
                    <flux:input wire:model.debounce.500ms.live="amount" label="Amount" icon="magnifying-glass" placeholder="123.45" />
                </div>
                <div class="flex-1 min-w-0">
                    <flux:input wire:model.debounce.500ms.live="check_number" label="Check Number" icon="magnifying-glass" placeholder="1234" />
                </div>
                <div class="flex-1 min-w-0">
                    {{-- 09-28-2024 NEED TYPE AND VENDOR FILTERS --}}
                    <flux:select wire:model.live="bank" label="Bank" placeholder="Select Bank..." variant="listbox" placeholder="Choose Bank...">
                        <flux:select.option value="">All Banks</flux:select.option>
                        @foreach ($banks->groupBy('plaid_ins_id') as $bank)
                            <flux:select.option value="{{$bank->first()->id}}">{{$bank->first()->name}}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>
        </x-island-card>
    @endif

    <x-island-card heading="Checks" :separator="true">

        <div class="space-y-4">
            <flux:table>
                <flux:table.columns>
                    {{-- sortable :sorted="$sortBy === 'amount'" :direction="$sortDirection" wire:click="sort('amount')"> --}}
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')">Date</flux:table.column>
                    <flux:table.column>Check #</flux:table.column>
                    <flux:table.column>Bank</flux:table.column>
                    @if($view === NULL)
                        <flux:table.column>Payee</flux:table.column>
                    @endif
                    <flux:table.column>Status</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->checks as $check)
                        <flux:table.row :key="$check->id">
                            <flux:table.cell
                                variant="strong"
                                class="cursor-pointer"
                                >
                                <a wire:navigate.hover href="{{route('checks.show', $check->id)}}">
                                    {{ money($check->amount) }}
                                </a>
                            </flux:table.cell>
                            <flux:table.cell>{{ $check->date->format('m/d/Y') }}</flux:table.cell>
                            <flux:table.cell>{{$check->check_type != 'Check' ? $check->check_type : $check->check_number}}</flux:table.cell>
                            <flux:table.cell>{{$check->bank_account?->bank?->name}}</flux:table.cell>
                            @if($view === NULL)
                                <flux:table.cell>{{$check->owner}}</flux:table.cell>
                            @endif
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$check->statusColor" inset="top bottom">{{ $check->status }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            @if($this->checks->hasPages())
                <div class="px-6 pb-6 pt-4">
                    <flux:pagination :paginator="$this->checks" />
                </div>
            @endif
        </div>
    </x-island-card>
</div>
