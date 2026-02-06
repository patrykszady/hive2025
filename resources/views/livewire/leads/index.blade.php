<div class="max-w-5xl">
    @if($view === NULL)
        <x-island-card heading="Lead Filters" :separator="true" class="mb-4">

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                {{-- <flux:input wire:model.debounce.500ms.live="amount" label="Amount" icon="magnifying-glass" placeholder="123.45" />
                <flux:input wire:model.debounce.500ms.live="check_number" label="Check Number" icon="magnifying-glass" placeholder="1234" /> --}}

                {{-- 09-28-2024 NEED TYPE AND VENDOR FILTERS --}}
                {{-- <flux:select wire:model.live="bank" label="Bank" placeholder="Select Bank..." variant="listbox" placeholder="Choose Bank...">
                    <flux:option value="">All Banks</flux:option>
                    @foreach ($banks->groupBy('plaid_ins_id') as $bank)
                        <flux:option value="{{$bank->first()->id}}">{{$bank->first()->name}}</flux:option>
                    @endforeach
                </flux:select> --}}
            </div>
        </x-island-card>
    @endif

    <x-island-card heading="Leads" :separator="true">
        <x-slot:actions>
            @can('create', App\Models\Project::class)
                <flux:button wire:click="$dispatchTo('leads.lead-create', 'addLead')">Add Lead</flux:button>
            @endcan
        </x-slot:actions>

        <div class="space-y-2">
            <flux:table :paginate="$this->leads->hasPages() ? $this->leads : null">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')">Date</flux:table.column>
                    <flux:table.column>User</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                    <flux:table.column>Last Contact</flux:table.column>
                    <flux:table.column>Origin</flux:table.column>
                    <flux:table.column>Address</flux:table.column>
                    {{--
                    @if($view === NULL)
                        <flux:column>Payee</flux:column>
                    @endif
                    --}}
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->leads as $lead)
                        <flux:table.row :key="$lead->id">
                            {{-- <flux:cell
                                variant="strong"
                                class="cursor-pointer"
                                >
                                <a wire:navigate.hover href="{{route('checks.show', $check->id)}}">
                                    {{ money($check->amount) }}
                                </a>
                            </flux:cell> --}}
                            <flux:table.cell
                                wire:click="$dispatchTo('leads.lead-create', 'editLead', { lead: {{$lead->id}}})"
                                variant="strong"
                                class="cursor-pointer"
                                >
                                {{ $lead->date->format('m/d/Y') }}
                            </flux:table.cell>

                            <flux:table.cell>
                                {{ $lead->lead_data['name'] }}
                            </flux:table.cell>

                            <flux:table.cell>
                                @if($lead->last_status)
                                    @php
                                        $color = $lead->last_status->title === 'New' ? 'yellow' : (in_array($lead->last_status->title, ['Message 1', 'Message 2', 'Message 3']) ? 'sky' : ($lead->last_status->title === 'Won' ? 'green' : (in_array($lead->last_status->title, ['Lost', "Not a Fit"]) ? 'red' : 'red')));
                                    @endphp
                                    <flux:badge color="{{$color}}">{{ $lead->last_status->title }}</flux:badge>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                @if($lead->last_status)
                                    @if(!in_array($lead->last_status->title, ['New', 'Won', 'Lost', 'Not a Fit']))
                                        {{ $lead->last_status->created_at->diffForHumans() }}
                                    @endif
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                {{ $lead->origin }}
                            </flux:table.cell>

                            <flux:table.cell>
                                {{ $lead->lead_data['address'] }}
                            </flux:table.cell>

                            {{-- <flux:cell>{{$check->check_type != 'Check' ? $check->check_type : $check->check_number}}</flux:cell>

                            @if($view === NULL)
                                <flux:cell>{{$check->owner}}</flux:cell>
                            @endif
                            <flux:cell>
                                <flux:badge size="sm" :color="$check->status == 'Complete' ? 'green' : ($check->status == 'Missing Transactions' ? 'yellow' : 'red')" inset="top bottom">{{ $check->status }}</flux:badge>
                            </flux:cell> --}}
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </x-island-card>
    <livewire:leads.lead-create />
</div>
