<div class="max-w-3xl">
    <flux:card class="space-y-2">
        <div class="flex justify-between">
            <flux:heading size="lg">Payments</flux:heading>
            <div>
                @can('create', App\Models\Payment::class)
                    @if($view === 'projects.show')
                        <flux:button wire:click="$dispatchTo('payments.payment-create', 'addProject', { client: {{$project->client->id}}})">Create Payment</flux:button>
                    @else
                        <flux:button wire:click="$dispatchTo('payments.payment-create', 'addProject')">Add Payment</flux:button>
                    @endif
                    <livewire:payments.payment-create />
                @endcan
            </div>
        </div>

        <div class="space-y-2">
            <flux:table :paginate="$this->payments">
                <flux:columns>
                    <flux:column>Amount</flux:column>
                    <flux:column sortable :sorted="$sortBy === 'date'" :direction="$sortDirection" wire:click="sort('date')">Date</flux:column>

                    @if($view != 'projects.show')
                        <flux:column>Project</flux:column>
                    @endif

                    <flux:column>Reference</flux:column>
                    <flux:column>Status</flux:column>
                </flux:columns>

                <flux:rows>
                    @foreach ($this->payments as $payment)
                        <flux:row :key="$payment->id">
                            <flux:cell
                                wire:click="$dispatchTo('payments.payment-create', 'editPayment', { payment: {{$payment->id}}})"
                                variant="strong"
                                class="cursor-pointer"
                                >
                                {{ money($payment->amount) }}
                            </flux:cell>
                            <flux:cell>{{ $payment->date->format('m/d/Y') }}</flux:cell>
                            @if($view != 'projects.show')
                                <flux:cell
                                    wire:navigate.hover
                                    href="{{route('projects.show', $payment->project->id)}}"
                                    class="cursor-pointer"
                                    >
                                    {{ $payment->project->name }}
                                </flux:cell>
                            @endif
                            <flux:cell>{{ $payment->reference }}</flux:cell>
                            <flux:cell>
                                <flux:badge size="sm" :color="$payment->transaction_id != NULL ? 'green' : 'red'" inset="top bottom">{{ $payment->transaction_id != NULL ? 'Complete' : 'Missing Transaction' }}</flux:badge>
                            </flux:cell>
                        </flux:row>
                    @endforeach
                </flux:rows>
            </flux:table>
        </div>
    </flux:card>
</div>
