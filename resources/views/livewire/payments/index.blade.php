<div class="max-w-4xl">
    <flux:card class="space-y-2">
        <div class="flex justify-between">
            <flux:heading size="lg">Payments</flux:heading>
            <div>
                @can('create', App\Models\Payment::class)
                    @if($view === 'projects.show')
                        <flux:button size="sm" wire:click="$dispatchTo('payments.payment-create', 'addProject', { client: {{$project->client->id}}})">Create Payment</flux:button>
                    @else
                        <flux:button size="sm" wire:click="$dispatchTo('payments.payment-create', 'addProject')">Add Payment</flux:button>
                    @endif
                    <livewire:payments.payment-create />
                @endcan
            </div>
        </div>

        <div class="space-y-2">
            <flux:table :paginate="$this->payments">
                <flux:table.columns>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column 
                        sortable 
                        :sorted="$sortBy === 'date'" 
                        :direction="$sortDirection" 
                        wire:click="sort('date')"
                        wire:loading.class="opacity-50"
                        wire:loading.attr="disabled"
                      
                        >
                        Date
                    </flux:table.column>

                    @if($view != 'projects.show')
                        <flux:table.column>Project</flux:table.column>
                        <flux:table.column>Client</flux:table.column>
                    @endif

                    <flux:table.column>Reference</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->payments as $payment)
                        <flux:table.row :key="$payment->id">
                            <flux:table.cell
                                wire:navigate.hover
                                href="{{route('payments.show', $payment->id)}}"
                                variant="strong"
                                class="cursor-pointer"
                                >
                                {{ money($payment->amount) }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $payment->date->format('m/d/Y') }}</flux:table.cell>
                            @if($view != 'projects.show')
                                <flux:table.cell
                                    wire:navigate.hover
                                    href="{{route('projects.show', $payment->project->id)}}"
                                    class="cursor-pointer"
                                    >
                                    {{ $payment->project->name }}
                                </flux:table.cell>
                                <flux:table.cell
                                    wire:navigate.hover
                                    href="{{route('clients.show', $payment->project->client->id)}}"
                                    class="cursor-pointer"
                                    >
                                    {{ $payment->project->client->name }}
                                </flux:table.cell>
                            @endif
                            <flux:table.cell>{{ $payment->reference }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$payment->transaction_id != NULL ? 'green' : 'red'" inset="top bottom">{{ $payment->transaction_id != NULL ? 'Complete' : 'Missing Transaction' }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>
</div>
