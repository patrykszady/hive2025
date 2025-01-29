<div class="max-w-3xl">
    @if($view === NULL)
        <flux:card class="space-y-2 mb-4">
            <div>
                <flux:heading size="lg">Check Filters</flux:heading>
            </div>

            <flux:separator variant="subtle" />

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <flux:input wire:model.debounce.500ms.live="amount" label="Amount" icon="magnifying-glass" placeholder="123.45" />
                <flux:input wire:model.debounce.500ms.live="check_number" label="Check Number" icon="magnifying-glass" placeholder="1234" />

                {{-- 09-28-2024 NEED TYPE AND VENDOR FILTERS --}}
                <flux:select wire:model.live="bank" label="Bank" placeholder="Select Bank..." variant="listbox" placeholder="Choose Bank...">
                    <flux:option value="">All Banks</flux:option>
                    @foreach ($banks->groupBy('plaid_ins_id') as $bank)
                        <flux:option value="{{$bank->first()->id}}">{{$bank->first()->name}}</flux:option>
                    @endforeach
                </flux:select>
            </div>
        </flux:card>
    @endif

    <flux:card class="space-y-2">
        <div>
            <flux:heading size="lg">Checks</flux:heading>
        </div>
        <flux:separator variant="subtle" />

        <div class="space-y-2">
            <flux:table :paginate="$this->checks">
                <flux:columns>
                    {{-- sortable :sorted="$sortBy === 'amount'" :direction="$sortDirection" wire:click="sort('amount')"> --}}
                    <flux:column>Amount</flux:column>
                    <flux:column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')">Date</flux:column>
                    <flux:column>Check #</flux:column>
                    <flux:column>Bank</flux:column>
                    @if($view === NULL)
                        <flux:column>Payee</flux:column>
                    @endif
                    <flux:column>Status</flux:column>
                </flux:columns>

                <flux:rows>
                    @foreach ($this->checks as $check)
                        <flux:row :key="$check->id">
                            <flux:cell
                                variant="strong"
                                class="cursor-pointer"
                                >
                                <a wire:navigate.hover href="{{route('checks.show', $check->id)}}">
                                    {{ money($check->amount) }}
                                </a>
                            </flux:cell>
                            <flux:cell>{{ $check->date->format('m/d/Y') }}</flux:cell>
                            <flux:cell>{{$check->check_type != 'Check' ? $check->check_type : $check->check_number}}</flux:cell>
                            <flux:cell>{{$check->bank_account->bank->name}}</flux:cell>
                            @if($view === NULL)
                                <flux:cell>{{$check->owner}}</flux:cell>
                            @endif
                            <flux:cell>
                                <flux:badge size="sm" :color="$check->status == 'Complete' ? 'green' : ($check->status == 'Missing Transactions' ? 'yellow' : 'red')" inset="top bottom">{{ $check->status }}</flux:badge>
                            </flux:cell>
                        </flux:row>
                    @endforeach
                </flux:rows>
            </flux:table>
        </div>
    </flux:card>
</div>
