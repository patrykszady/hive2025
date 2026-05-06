<div class="max-w-3xl space-y-2" wire:transition>
    @if($view === null)
        <flux:card class="!px-5 !py-2 sm:hidden">
            <flux:accordion transition>
                <flux:accordion.item>
                    <div class="flex items-center">
                        <div class="flex-1 min-w-0">
                            <flux:accordion.heading>
                                <flux:heading size="lg">Filters</flux:heading>
                            </flux:accordion.heading>
                        </div>
                    </div>
                    <flux:accordion.content>
                        @include('livewire.payments.partials.filter-fields', ['layout' => 'stacked'])
                    </flux:accordion.content>
                </flux:accordion.item>
            </flux:accordion>
        </flux:card>

        <x-island-card heading="Filters" :separator="true" class="hidden sm:block">
            @include('livewire.payments.partials.filter-fields', ['layout' => 'inline'])
        </x-island-card>
    @endif

    <x-island-card heading="Payments">
        <x-slot:actions>
        @if($view !== 'estimate.pdf')
            @can('create', App\Models\Payment::class)
                @if($view === 'projects.show' && $project->finances['balance'] > 0 && $project->latestStatus?->title !== 'VIEW ONLY')
                    <flux:button size="sm" wire:click="$dispatchTo('payments.payment-create', 'addProject', { client: {{$project->client->id}}})">Create Payment</flux:button>
                @elseif($view !== 'projects.show' && $this->hasClientsWithProjects)
                    <flux:button size="sm" wire:click="$dispatchTo('payments.payment-create', 'addProject')">Add Payment</flux:button>
                @endif
                <livewire:payments.payment-create />
            @endcan
        @endif
        </x-slot:actions>

        @if($this->payments->isNotEmpty())
        <div class="space-y-2">
            <flux:table :paginate="$view !== 'estimate.pdf' && $this->payments->hasPages() ? $this->payments : null">
                <flux:table.columns>
                    <flux:table.column>Amount</flux:table.column>
                    @if($view !== 'estimate.pdf')
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
                    @else
                        <flux:table.column>Date</flux:table.column>
                    @endif

                    @if(!in_array($view, ['projects.show', 'estimate.pdf']))
                        <flux:table.column>Project</flux:table.column>
                        <flux:table.column>Client</flux:table.column>
                    @endif

                    <flux:table.column>Reference</flux:table.column>
                    <flux:table.column>Status</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->payments as $payment)
                        <flux:table.row :key="$payment->id">
                            @if(auth()->user()->is_browsing_as_client)
                                <flux:table.cell variant="strong">
                                    {{ money($payment->amount) }}
                                </flux:table.cell>
                            @elseif($view === 'projects.show' && $project->latestStatus?->title === 'VIEW ONLY')
                                <flux:table.cell variant="strong">
                                    {{ money($payment->amount) }}
                                </flux:table.cell>
                            @else
                                <flux:table.cell
                                    wire:navigate.hover
                                    href="{{route('payments.show', $payment->id)}}"
                                    variant="strong"
                                    class="cursor-pointer transition-colors hover:text-indigo-600 dark:hover:text-indigo-400"
                                    >
                                    {{ money($payment->amount) }}
                                </flux:table.cell>
                            @endif
                            <flux:table.cell>{{ $payment->date->format('m/d/Y') }}</flux:table.cell>
                            @if(!in_array($view, ['projects.show', 'estimate.pdf']))
                                @if($payment->project)
                                    <flux:table.cell
                                        wire:navigate.hover
                                        href="{{route('projects.show', $payment->project->id)}}"
                                        class="cursor-pointer transition-colors hover:text-indigo-600 dark:hover:text-indigo-400"
                                        >
                                        {{ $payment->project->name }}
                                    </flux:table.cell>
                                @else
                                    <flux:table.cell>—</flux:table.cell>
                                @endif

                                @if($payment->project?->client)
                                    <flux:table.cell
                                        wire:navigate.hover
                                        href="{{route('clients.show', $payment->project->client->id)}}"
                                        class="cursor-pointer transition-colors hover:text-indigo-600 dark:hover:text-indigo-400"
                                        >
                                        {{ $payment->project->client->last_names }}
                                    </flux:table.cell>
                                @else
                                    <flux:table.cell>—</flux:table.cell>
                                @endif
                            @endif
                            <flux:table.cell>{{ $payment->reference }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$payment->transaction_id != NULL ? 'green' : 'red'" inset="top bottom">{{ $payment->transaction_id != NULL ? 'Complete' : 'Missing' }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
        @endif
    </x-island-card>
</div>
