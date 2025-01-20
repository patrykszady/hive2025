<div class="max-w-5xl">
    @if($view === NULL)
        <flux:card class="space-y-2 mb-4">
            <div>
                <flux:heading size="lg">Lead Filters</flux:heading>
            </div>

            <flux:separator variant="subtle" />

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
        </flux:card>
    @endif

    <flux:card class="space-y-2">
        <div class="flex justify-between">
            <flux:heading size="lg">Leads</flux:heading>
            @can('create', App\Models\Project::class)
                {{-- , { client_id: '{{$view === NULL ? $client_id : $client->id}}' } --}}
                <flux:button wire:click="$dispatchTo('leads.lead-create', 'addLead')">Add Lead</flux:button>
            @endcan
        </div>

        <flux:separator variant="subtle" />

        <div class="space-y-2">
            <flux:table :paginate="$this->leads">
                <flux:columns>
                    <flux:column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')">Date</flux:column>
                    <flux:column>User</flux:column>
                    <flux:column>Status</flux:column>
                    <flux:column>Last Contact</flux:column>
                    <flux:column>Origin</flux:column>
                    <flux:column>Address</flux:column>
                    {{--
                    @if($view === NULL)
                        <flux:column>Payee</flux:column>
                    @endif
                    --}}
                </flux:columns>
                <flux:rows>
                    @foreach ($this->leads as $lead)
                        <flux:row :key="$lead->id">
                            {{-- <flux:cell
                                variant="strong"
                                class="cursor-pointer"
                                >
                                <a wire:navigate.hover href="{{route('checks.show', $check->id)}}">
                                    {{ money($check->amount) }}
                                </a>
                            </flux:cell> --}}
                            <flux:cell
                                wire:click="$dispatchTo('leads.lead-create', 'editLead', { lead: {{$lead->id}}})"
                                variant="strong"
                                class="cursor-pointer"
                                >
                                {{ $lead->date->format('m/d/Y') }}
                            </flux:cell>

                            <flux:cell>
                                {{ $lead->lead_data['name'] }}
                            </flux:cell>

                            <flux:cell>
                                @if($lead->last_status)
                                    @php
                                        $color = $lead->last_status->title === 'New' ? 'yellow' : (in_array($lead->last_status->title, ['Message 1', 'Message 2', 'Message 3']) ? 'sky' : ($lead->last_status->title === 'Won' ? 'green' : (in_array($lead->last_status->title, ['Lost', "Not a Fit"]) ? 'red' : 'red')));
                                    @endphp
                                    <flux:badge color="{{$color}}">{{ $lead->last_status->title }}</flux:badge>
                                @endif
                            </flux:cell>

                            <flux:cell>
                                @if($lead->last_status)
                                    @if(!in_array($lead->last_status->title, ['New', 'Won', 'Lost', 'Not a Fit']))
                                        {{ $lead->last_status->created_at->diffForHumans() }}
                                    @endif
                                @endif
                            </flux:cell>

                            <flux:cell>
                                {{ $lead->origin }}
                            </flux:cell>

                            <flux:cell>
                                {{ $lead->lead_data['address'] }}
                            </flux:cell>

                            {{-- <flux:cell>{{$check->check_type != 'Check' ? $check->check_type : $check->check_number}}</flux:cell>

                            @if($view === NULL)
                                <flux:cell>{{$check->owner}}</flux:cell>
                            @endif
                            <flux:cell>
                                <flux:badge size="sm" :color="$check->status == 'Complete' ? 'green' : ($check->status == 'Missing Transactions' ? 'yellow' : 'red')" inset="top bottom">{{ $check->status }}</flux:badge>
                            </flux:cell> --}}
                        </flux:row>
                    @endforeach
                </flux:rows>
            </flux:table>
        </div>
    </flux:card>
    <livewire:leads.lead-create />
</div>
