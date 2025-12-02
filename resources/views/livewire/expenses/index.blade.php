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

    <flux:card class="overflow-hidden">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Expenses</flux:heading>
            </div>

            <div class="-mx-6 -mb-6 overflow-x-hidden">
                <flux:table
                    wire:loading.class="opacity-50 text-opacity-50"
                    class="table-fixed w-full [:where(&)]:p-0 [:where(&)]:space-y-0 [&_th]:!px-4 [&_td]:!px-3 [&_th:first-child]:!ps-6 [&_th:last-child]:!pe-6 [&_td:first-child]:!ps-6 [&_td:last-child]:!pe-6"
                >
                <flux:table.columns>
                    <flux:table.column class="w-[14%] min-w-[5.5rem] !pe-8">
                        <div class="pe-4">Amount</div>
                    </flux:table.column>
                    <flux:table.column
                        sortable
                        :sorted="$sortBy === 'date'"
                        :direction="$sortDirection"
                        wire:click="sort('date')"
                        class="w-[14%] min-w-[6rem] !ps-8 !pe-3"
                        >
                        <div class="ps-4">Date</div>
                    </flux:table.column>

                    @if(!in_array($view, ['checks.show', 'vendors.show']))
                        <flux:table.column class="w-[25%] min-w-0 !ps-3">Vendor</flux:table.column>
                    @endif

                    @if($view != 'projects.show')
                        <flux:table.column class="w-[30%] min-w-0">Project</flux:table.column>
                    @endif
                    <flux:table.column align="end" class="w-[17%] min-w-[5rem] shrink-0">Status</flux:table.column>
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
                                class="cursor-pointer w-[14%] min-w-[5.5rem] !pe-8"
                                >
                                <div class="pe-4">{{ display_money($expense->amount) }}</div>
                            </flux:table.cell>
                            <flux:table.cell class="w-[14%] min-w-[6rem] !ps-8 !pe-3">
                                <div class="ps-4">{{ $expense->date->format('m/d/y') }}</div>
                            </flux:table.cell>
                            @if(!in_array($view, ['checks.show', 'vendors.show']))
                                <flux:table.cell class="w-[25%] min-w-0 !ps-3">
                                    <a href="{{isset($expense->vendor->id) ? route('vendors.show', $expense->vendor->id) : ''}}">
                                        <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{$expense->vendor->name}}">{{$expense->vendor->name}}</div>
                                    </a>
                                </flux:table.cell>
                            @endif

                            @if($view != 'projects.show')
                                <flux:table.cell class="w-[30%] min-w-0">
                                    @if($expense->splits->count() > 0)
                                        SPLIT
                                    @else
                                        <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{ $expense->project?->name ?? 'No Project' }}">{{ $expense->project?->name ?? 'No Project' }}</div>
                                    @endif
                                </flux:table.cell>
                            @endif
                            <flux:table.cell align="end" class="w-[17%] min-w-[5rem] shrink-0">
                                {{-- Just use status directly, no fallback needed if coming from search --}}
                                <div class="flex justify-end">
                                    <flux:badge size="sm" inset="top bottom" color="{{$expense->status_color}}" class="max-w-[8rem] overflow-hidden text-ellipsis whitespace-nowrap">
                                        {{$expense->status}}
                                    </flux:badge>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>

                        {{-- Show split rows if expense has splits --}}
                        @if($expense->splits->count() > 0)
                            @foreach($expense->splits as $split)
                                @if($view === 'projects.show' && (string)$split->project_id !== (string)$project_id)
                                    @continue
                                @endif
                                <flux:table.row :key="'split-' . $split->id" class="bg-gray-50 dark:bg-gray-800/50 [&_td]:!py-2">
                                    <flux:table.cell class="text-sm text-gray-600 dark:text-gray-400 tabular-nums w-[14%] min-w-[5.5rem] !pl-10 !pe-8">
                                        <div class="pe-4">{{ display_money($split->amount) }}</div>
                                    </flux:table.cell>
                                    {{-- Preserve column alignment: empty date cell --}}
                                    <flux:table.cell class="w-[14%] min-w-[6rem] !ps-8 !pe-3">
                                        <div class="ps-4"></div>
                                    </flux:table.cell>
                                    @if(!in_array($view, ['checks.show', 'vendors.show']))
                                        {{-- Empty vendor cell for split rows --}}
                                        <flux:table.cell class="w-[25%] min-w-0 !ps-3"></flux:table.cell>
                                    @endif
                                    @if($view != 'projects.show')
                                        <flux:table.cell class="text-sm text-gray-600 dark:text-gray-400 w-[30%] min-w-0">
                                            {{-- Prefer distribution name, then project accessor; link if project exists --}}
                                            @php
                                                $splitProjectName = '';
                                                if(!is_null($split->distribution_id) && isset($split->distribution->name)) {
                                                    $splitProjectName = $split->distribution->name;
                                                } elseif(isset($split->project->id)) {
                                                    $splitProjectName = $split->project->name;
                                                } else {
                                                    $splitProjectName = 'No Project';
                                                }
                                            @endphp
                                            <div class="truncate whitespace-nowrap overflow-hidden text-ellipsis" title="{{ $splitProjectName }}">{{ $splitProjectName }}</div>
                                        </flux:table.cell>
                                    @endif
                                    <flux:table.cell align="end" class="text-sm text-gray-600 dark:text-gray-400 w-[17%] min-w-[5rem] shrink-0">
                                        <flux:badge size="sm" variant="outline" color="gray">Split</flux:badge>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        @endif
                    @endforeach
                </flux:table.rows>
                </flux:table>

                <div class="px-6 pb-6 pt-4">
                    <flux:pagination :paginator="$this->expenses" />
                </div>
            </div>
        </div>
    </flux:card>

    @if($view === NULL && auth()->user()->can('create', App\Models\Expense::class))
        <flux:card wire:init="loadTransactions" x-data="{
            init() {
                window.addEventListener('remove-transaction-row', (event) => {
                    const transactionId = event.detail.id;
                    const row = document.querySelector(`[wire\\\\:key='${transactionId}']`);
                    if (row) {
                        row.style.transition = 'opacity 0.3s ease-out';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 300);
                    }
                });
            }
        }">
            <div>
                <flux:heading size="lg">Transactions</flux:heading>
            </div>

            <div>
                @if($this->transactionsReady)
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
                @else
                    <div class="p-4 text-sm text-zinc-500">Loading transactions…</div>
                @endif
            </div>
        </flux:card>
    @endif

    <livewire:expenses.expense-create />
</div>