<div class="max-w-3xl space-y-2" wire:transition>
    @if($view === null)
        <x-filter-card>
            {{-- single copy: the inline layout stacks below sm on its own --}}
            @include('livewire.payments.partials.filter-fields', ['layout' => 'inline'])
        </x-filter-card>
    @endif

    {{-- Standalone page: lazy island paints the shared skeleton first, like
         every other index card. Embedded variants render immediately (they are
         already lazy-mounted and use placeholder() instead). --}}
    @island(name: 'payments-table', lazy: island_lazy($view === null), always: true)
        @placeholder
            <x-index-table.placeholder
                heading="Payments"
                :columns="\App\Livewire\Payments\PaymentsIndex::columnDefs($view)"
                :rows="\App\Livewire\Payments\PaymentsIndex::placeholderRows($view)"
                :compact="false"
            />
        @endplaceholder
    <x-index-table heading="Payments" :paginator="$view !== 'estimate.pdf' ? $this->payments : null">
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
            <flux:table class="{{ in_array($view, ['projects.show', 'estimate.pdf']) ? 'table-fixed min-w-0 w-full' : 'index-table' }} [:where(&)]:p-0 [:where(&)]:space-y-0">
                <flux:table.columns>
                    <flux:table.column class="{{ !in_array($view, ['projects.show', 'estimate.pdf']) ? 'w-[13%]' : '' }}">Amount</flux:table.column>
                    @if($view !== 'estimate.pdf')
                        <flux:table.column 
                            sortable 
                            :sorted="$sortBy === 'date'" 
                            :direction="$sortDirection" 
                            wire:click="sort('date')"
                            wire:loading.class="opacity-50"
                            wire:loading.attr="disabled"
                            class="{{ !in_array($view, ['projects.show', 'estimate.pdf']) ? 'w-[12%]' : '' }}"
                            >
                            Date
                        </flux:table.column>
                    @else
                        <flux:table.column class="{{ !in_array($view, ['projects.show', 'estimate.pdf']) ? 'w-[12%]' : '' }}">Date</flux:table.column>
                    @endif

                    @if(!in_array($view, ['projects.show', 'estimate.pdf']))
                        <flux:table.column class="w-[25%] min-w-0">Project</flux:table.column>
                        <flux:table.column class="w-[16%] min-w-0">Client</flux:table.column>
                    @endif

                    <flux:table.column class="{{ !in_array($view, ['projects.show', 'estimate.pdf']) ? 'w-[18%] min-w-0' : '' }}">Reference</flux:table.column>
                    <flux:table.column class="{{ !in_array($view, ['projects.show', 'estimate.pdf']) ? 'w-[16%]' : '' }}">Status</flux:table.column>
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
                            <flux:table.cell class="whitespace-nowrap">{{ $payment->date->format('m/d/Y') }}</flux:table.cell>
                            @if(!in_array($view, ['projects.show', 'estimate.pdf']))
                                @if($payment->project)
                                    <flux:table.cell
                                        wire:navigate.hover
                                        href="{{route('projects.show', $payment->project->id)}}"
                                        class="cursor-pointer transition-colors hover:text-indigo-600 dark:hover:text-indigo-400 min-w-0"
                                        >
                                        <x-truncate-tooltip :content="$payment->project->name">
                                            <div class="truncate">{{ $payment->project->name }}</div>
                                        </x-truncate-tooltip>
                                    </flux:table.cell>
                                @else
                                    <flux:table.cell>—</flux:table.cell>
                                @endif

                                @if($payment->project?->client)
                                    <flux:table.cell
                                        wire:navigate.hover
                                        href="{{route('clients.show', $payment->project->client->id)}}"
                                        class="cursor-pointer transition-colors hover:text-indigo-600 dark:hover:text-indigo-400 min-w-0"
                                        >
                                        <x-truncate-tooltip :content="$payment->project->client->last_names">
                                            <div class="truncate">{{ $payment->project->client->last_names }}</div>
                                        </x-truncate-tooltip>
                                    </flux:table.cell>
                                @else
                                    <flux:table.cell>—</flux:table.cell>
                                @endif
                            @endif
                            <flux:table.cell class="min-w-0">
                                <x-truncate-tooltip :content="(string) $payment->reference">
                                    <div class="truncate">{{ $payment->reference }}</div>
                                </x-truncate-tooltip>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$payment->transaction_id != NULL ? 'green' : 'red'" inset="top bottom">{{ $payment->transaction_id != NULL ? 'Complete' : 'Missing' }}</flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </x-index-table>
    @endisland
</div>
