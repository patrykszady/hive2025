<div class="max-w-3xl">
    @if($view === NULL)
        <flux:card class="space-y-2 mb-4">
            <div class="flex justify-between">
                <flux:heading size="lg">Filters</flux:heading>
                @can('create', App\Models\Expense::class)
                    @if($amount && $view == NULL)
                        <flux:button wire:click="$dispatchTo('expenses.expense-create', 'newExpense', { amount: {{$amount}}})">Add New Expense</flux:button>
                    @endif
                @endcan
            </div>

            <flux:separator variant="subtle" />

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <flux:input wire:model.live.debounce.300ms="amount" label="Amount" icon="magnifying-glass" placeholder="Search Amount" />

                <flux:select wire:model.live="expense_vendor" label="Vendor" variant="listbox" searchable placeholder="Choose Vendor...">
                    <x-slot name="search">
                        <flux:select.search placeholder="Search..." />
                    </x-slot>

                    <flux:option value="">ALL VENDORS</flux:option>
                    <flux:option value="0">NO VENDOR</flux:option>
                    <flux:option disabled>---------</flux:option>
                    @foreach ($vendors as $vendor)
                        <flux:option value="{{$vendor->id}}">{{ $vendor->name }}</flux:option>
                    @endforeach
                </flux:select>

                {{-- <flux:select wire:model.live="project_id" label="Project" variant="listbox" searchable placeholder="Choose Project...">
                    <x-slot name="search">
                        <flux:select.search placeholder="Search..." />
                    </x-slot>

                    <flux:option value="">ALL PROJECTS</flux:option>
                    <flux:option value="NO_PROJECT">NO PROJECT</flux:option>
                    <flux:option value="SPLIT">SPLIT</flux:option>
                    <flux:option disabled>---------</flux:option>
                    @foreach ($projects as $project)
                        <flux:option value="{{$project->id}}">{{ $project->name }}</flux:option>
                    @endforeach
                    <flux:option disabled>---------</flux:option>
                    @foreach ($distributions as $distribution)
                        <flux:option value="D:{{$distribution->id}}">{{ $distribution->name }}</flux:option>
                    @endforeach
                </flux:select> --}}
            </div>
        </flux:card>
    @endif

    <flux:card class="space-y-2">
        <div>
            <flux:heading size="lg">Expenses</flux:heading>
        </div>

        <div class="space-y-2">
            <flux:table :paginate="$expenses" wire:loading.class="opacity-50 text-opacity-50">
                <flux:columns>
                    <flux:column>Amount</flux:column>
                    <flux:column
                        sortable
                        :sorted="$sortBy === 'date'"
                        :direction="$sortDirection"
                        wire:click="sort('date')"
                        >
                        Date
                    </flux:column>

                    @if(!in_array($view, ['checks.show', 'vendors.show']))
                        <flux:column >Vendor</flux:column>
                    @endif

                    @if($view != 'projects.show')
                        <flux:column>Project</flux:column>
                    @endif
                    <flux:column>Status</flux:column>
                </flux:columns>

                <flux:rows>
                    @foreach ($expenses as $expense)
                        <flux:row :key="$expense->id">
                            <flux:cell
                                wire:click="$dispatchTo('expenses.expense-create', 'editExpense', { expense: {{$expense->id}}})"
                                variant="strong"
                                class="cursor-pointer"
                                >
                                {{ money($expense->amount) }}
                            </flux:cell>
                            <flux:cell>{{ $expense->date->format('m/d/Y') }}</flux:cell>
                            @if(!in_array($view, ['checks.show', 'vendors.show']))
                                <flux:cell><a href="{{isset($expense->vendor->id) ? route('vendors.show', $expense->vendor->id) : ''}}">{{Str::limit($expense->vendor->name, 20)}}</a></flux:cell>
                            @endif

                            @if($view != 'projects.show')
                                <flux:cell>
                                    @if($expense->project_id)
                                        <a wire:navigate.hover href="{{route('projects.show', $expense->project->id)}}">{{ Str::limit($expense->project->name, 25) }}</a>
                                    @else
                                        {{ Str::limit($expense->project->name, 25) }}
                                    @endif
                                </flux:cell>
                            @endif
                            <flux:cell>
                                <flux:badge size="sm" :color="'sky'" inset="top bottom">Status</flux:badge>
                                {{-- <flux:badge size="sm" :color="$expense->status == 'Complete' ? 'green' : ($expense->status == 'No Transaction' ? 'yellow' : 'red')" inset="top bottom">{{ $expense->status }}</flux:badge> --}}
                            </flux:cell>
                        </flux:row>
                    @endforeach
                </flux:rows>
            </flux:table>
        </div>
    </flux:card>

    <livewire:expenses.expense-create />
</div>
{{--

<div>
    <x-cards.heading>
        <div class="mx-auto">
            <div>
                <select
                    wire:model.live="bank_plaid_ins_id"
                    id="bank_plaid_ins_id"
                    name="bank_plaid_ins_id"
                    class="block w-full py-2 pl-3 pr-10 mt-1 text-base border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    <option value="" readonly>All Banks</option>
                    @foreach($banks as $institution_id => $bank)
                        <option value="{{$institution_id}}">{{$bank->first()->name}}</option>
                    @endforeach
                </select>
            </div>
            @if(!empty($bank_owners))
                <div>
                    <select
                        wire:model.live="bank_owner"
                        id="bank_owner"
                        name="bank_owner"
                        class="block w-full py-2 pl-3 pr-10 mt-1 text-base border-gray-300 rounded-md focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        <option value="" readonly>All Owners</option>
                        @foreach($bank_owners as $owner)
                            <option value="{{$owner}}">{{$owner}}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
    </x-cards.heading>
</div> --}}
