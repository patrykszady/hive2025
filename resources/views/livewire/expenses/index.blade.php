<div class="max-w-3xl space-y-2">
    @if($view === NULL)
        <flux:card>
            <div class="flex justify-between">
                <flux:heading size="lg">Filters</flux:heading>
                @can('create', App\Models\Expense::class)
                    @if($amount && $view == NULL)
                        <flux:button wire:click="$dispatchTo('expenses.expense-create', 'newExpense', { amount: {{$amount}}})">Add New Expense</flux:button>
                    @endif
                @endcan
            </div>

            <flux:separator variant="subtle" />

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <flux:input wire:model.live.debounce.300ms="amount" label="Amount" icon="magnifying-glass" placeholder="Search Amount" />

                <flux:select wire:model.live="expense_vendor" label="Vendor" variant="listbox" searchable placeholder="Choose Vendor...">
                    <x-slot name="search">
                        <flux:select.search placeholder="Search..." />
                    </x-slot>

                    <flux:select.option value="">ALL VENDORS</flux:select.option>
                    <flux:select.option value="0">NO VENDOR</flux:select.option>
                    <flux:select.option disabled>---------</flux:select.option>
                    @foreach ($vendors as $vendor)
                        <flux:select.option value="{{$vendor->id}}">{{ $vendor->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="project_id" label="Project" variant="listbox" searchable placeholder="Choose Project...">
                    <x-slot name="search">
                        <flux:select.search placeholder="Search..." />
                    </x-slot>

                    <flux:select.option value="">ALL PROJECTS</flux:select.option>
                    <flux:select.option value="NO_PROJECT">NO PROJECT</flux:select.option>
                    <flux:select.option value="SPLIT">SPLIT</flux:select.option>
                    <flux:select.option disabled>---------</flux:select.option>                                        
                    @foreach ($distributions as $distribution)
                        <flux:select.option value="D:{{$distribution->id}}">{{ $distribution->name }}</flux:select.option>
                    @endforeach
                    <flux:select.option disabled>---------</flux:select.option>
                    @foreach ($projects as $project)
                        <flux:select.option value="{{$project->id}}"><div>{{$project->address}} <br> <i class="font-normal">{{$project->project_name}}</i></div></flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select variant="listbox" label="Status" multiple placeholder="Choose status..." wire:model.live="expense_statuses">
                    <flux:select.option value="Complete"><flux:badge size="md" inset="top bottom" color="green">Complete</flux:badge></flux:select.option>
                    <flux:select.option value="No Transaction"><flux:badge size="md" inset="top bottom" color="yellow">No Transaction</flux:badge></flux:select.option>
                    <flux:select.option value="No Project"><flux:badge size="md" inset="top bottom" color="red">No Project</flux:badge></flux:select.option>
                    <flux:select.option value="Missing Info"><flux:badge size="md" inset="top bottom" color="amber">Missing Info</flux:badge></flux:select.option>
                </flux:select>
            </div>
        </flux:card>
    @endif

    <flux:card>
        <div>
            <flux:heading size="lg">Expenses</flux:heading>
        </div>

        <div class="space-y-2">
            <flux:table :paginate="$this->expenses" wire:loading.class="opacity-50 text-opacity-50">
                <flux:table.columns>
                    <flux:table.column>Amount</flux:table.column>
                    <flux:table.column
                        sortable
                        :sorted="$sortBy === 'date'"
                        :direction="$sortDirection"
                        wire:click="sort('date')"
                        >
                        Date
                    </flux:table.column>

                    @if(!in_array($view, ['checks.show', 'vendors.show']))
                        <flux:table.column >Vendor</flux:table.column>
                    @endif

                    @if($view != 'projects.show')
                        <flux:table.column>Project</flux:table.column>
                    @endif
                    <flux:table.column>Status</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->expenses as $expense)
                        <flux:table.row :key="$expense->id">
                            <flux:table.cell
                                x-data="{
                                    canEdit: {{ auth()->user()->can('create', App\Models\Expense::class) ? 'true' : 'false' }},
                                    showUrl: '{{ route('expenses.show', $expense->id) }}'
                                }"
                                @click="canEdit ? $wire.dispatchTo('expenses.expense-create', 'editExpense', { expense: {{ $expense->id }} }) : window.location.href = showUrl"
                                variant="strong"
                                class="cursor-pointer"
                                >
                                {{ money($expense->amount) }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $expense->date->format('m/d/Y') }}</flux:table.cell>
                            @if(!in_array($view, ['checks.show', 'vendors.show']))
                                <flux:table.cell><a href="{{isset($expense->vendor->id) ? route('vendors.show', $expense->vendor->id) : ''}}">{{Str::limit($expense->vendor->name, 20)}}</a></flux:table.cell>
                            @endif

                            @if($view != 'projects.show')
                                <flux:table.cell>
                                    {{ isset($expense->project['address']) ? $expense->project['address'] . ' | ' . $expense->project['project_name'] : $expense->project['project_name'] }}
                                </flux:table.cell>
                            @endif
                            <flux:table.cell>
                                {{-- Just use status directly, no fallback needed if coming from search --}}
                                <flux:badge size="sm" inset="top bottom" color="{{$expense->status_color}}">
                                    {{$expense->status}}
                                </flux:badge>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>

    @if($view === NULL && auth()->user()->can('create', App\Models\Expense::class))
        <flux:card>
            <div>
                <flux:heading size="lg">Transactions</flux:heading>
            </div>

            <div>
                    <flux:table :paginate="$this->transactions" wire:loading.class="opacity-50 text-opacity-50">
                        <flux:table.columns>
                            <flux:table.column>Amount</flux:table.column>
                            <flux:table.column>Date</flux:table.column>
                            <flux:table.column>Vendor</flux:table.column>
                            <flux:table.column>Bank</flux:table.column>
                            <flux:table.column>Account</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->transactions as $transaction)
                                <flux:table.row :key="$transaction->id">
                                    <flux:table.cell
                                        wire:click="$dispatchTo('expenses.expense-create', 'createExpenseFromTransaction', { transaction: {{$transaction->id}}})"
                                        variant="strong"
                                        class="cursor-pointer"
                                        >
                                        {{ money($transaction->amount) }}
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $transaction->transaction_date->format('m/d/Y') }}</flux:table.cell>
                                    <flux:table.cell class="max-w-[150px] truncate" title="{{ $transaction->vendor->name != 'No Vendor' ? $transaction->vendor->name : $transaction->plaid_merchant_description }}">
                                        {{ $transaction->vendor->name != 'No Vendor' ? $transaction->vendor->name : $transaction->plaid_merchant_description }}
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $transaction->bank_account->bank->name }}</flux:table.cell>
                                    <flux:table.cell>{{ isset($transaction->owner) ? $transaction->owner : $transaction->bank_account->account_number }}</flux:table.cell>
                                    {{--
                                    @if(!in_array($view, ['checks.show', 'vendors.show']))
                                        <flux:table.cell><a href="{{isset($expense->vendor->id) ? route('vendors.show', $expense->vendor->id) : ''}}">{{Str::limit($expense->vendor->name, 20)}}</a></flux:table.cell>
                                    @endif

                                    @if($view != 'projects.show')
                                        <flux:table.cell>
                                            @if($expense->project_id)
                                                <a wire:navigate.hover href="{{route('projects.show', $expense->project->id)}}">{{ Str::limit($expense->project->name, 25) }}</a>
                                            @else
                                                {{ Str::limit($expense->project->name, 25) }}
                                            @endif
                                        </flux:table.cell>
                                    @endif
                                    <flux:table.cell>
                                        <flux:badge size="sm" :color="'sky'" inset="top bottom">Status</flux:badge>
                                    </flux:table.cell> --}}
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
            </div>
        </flux:card>
    @endif

    <livewire:expenses.expense-create />
</div>