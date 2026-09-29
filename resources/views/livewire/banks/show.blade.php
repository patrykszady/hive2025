@php
    // Computed here, not inside the slots: an @if INSIDE a slot passed to a
    // @blaze component (x-index-table) is silently dropped.
    $onIndex = Route::is('banks.index');
    $errorCode = $bank->error['error_code'] ?? null;
    $errorSubheading = $errorCode
        ? new \Illuminate\Support\HtmlString('<span class="text-red-700 dark:text-red-400">'.e($errorCode).'</span>')
        : null;
    // Short-circuits on /banks: the statements lookup is a Plaid call per bank.
    $showStatements = ! $onIndex && $this->supportsStatements;
@endphp
<div class="max-w-lg">
    {{-- Same shared card as Checks, Payments and Vendor Documents. --}}
    <x-index-table
        :heading="$bank->name"
        :href="$onIndex ? route('banks.show', $bank->id) : null"
        :subheading="$errorSubheading"
    >
        <x-slot:badge>
            <flux:badge inset="top bottom" size="sm" :color="$errorCode ? 'red' : 'green'">{{ $errorCode ? 'Error' : 'Connected' }}</flux:badge>
        </x-slot:badge>

        <x-slot:actions>
            <div class="{{ $onIndex ? 'hidden' : 'contents' }}">
                <div class="{{ $showStatements ? 'contents' : 'hidden' }}">
                    <flux:button wire:click="downloadLatestStatement" size="sm" variant="filled" icon="arrow-down-tray" wire:loading.attr="disabled" wire:target="downloadLatestStatement">Latest Statement</flux:button>
                </div>
                <flux:button.group>
                    <flux:button wire:navigate.hover wire:click="plaid_link_token_update" size="sm">Update Bank Account</flux:button>
                    <flux:dropdown position="bottom" align="end">
                        <flux:button icon-trailing="chevron-down" size="sm"></flux:button>

                        <flux:menu>
                            <flux:modal.trigger name="relink-bank-{{ $bank->id }}">
                                <flux:menu.item icon="arrow-path">Reconnect as New</flux:menu.item>
                            </flux:modal.trigger>
                        </flux:menu>
                    </flux:dropdown>
                </flux:button.group>
            </div>
        </x-slot:actions>

        <x-slot:subheading_actions>
            <div class="text-xs italic text-zinc-500 dark:text-zinc-400">{{ $bank->updated_at->diffForHumans() }}</div>
        </x-slot:subheading_actions>

        @if(collect($accounts)->isNotEmpty())
            <x-index-table.table :columns="\App\Livewire\Banks\BankShow::columnDefs()">
                @foreach($accounts as $bank_account_number => $bank_account_types)
                    @foreach($bank_account_types as $bank_account_type => $bank_account_data)
                        <flux:table.row :key="'account-'.$bank_account_data['account']->id">
                            <flux:table.cell variant="strong" class="w-[37%] min-w-0">
                                <div class="flex items-center gap-2">
                                    <span>{{ $bank_account_number }}</span>
                                    <flux:badge inset="top bottom" size="sm" :color="$bank_account_data['account']->trashed() ? 'zinc' : 'blue'">{{ $bank_account_type }}</flux:badge>
                                </div>
                            </flux:table.cell>
                            <flux:table.cell class="w-[40%] min-w-0">
                                @if(isset($bank_account_data['account']->options->last_balance_update))
                                    <span class="text-xs italic">{{ \Carbon\Carbon::parse($bank_account_data['account']->options->last_balance_update)->diffForHumans() }}</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell variant="strong" align="end" class="w-[23%] tabular-nums">
                                @if(isset($bank_account_data['account']->options->balances))
                                    {{ money($bank_account_data['account']->options->balances->available ?? $bank_account_data['account']->options->balances->current ?? '') }}
                                @endif
                            </flux:table.cell>
                        </flux:table.row>

                        @foreach($bank_account_data['checks'] as $check)
                            <flux:table.row :key="'check-'.$check->id">
                                <flux:table.cell class="w-[37%] min-w-0">
                                    <x-truncate-tooltip :content="$check->owner">
                                        <a wire:navigate.hover href="{{ route('checks.show', $check->id) }}" class="block truncate ps-2">{{ $check->owner }}</a>
                                    </x-truncate-tooltip>
                                </flux:table.cell>
                                <flux:table.cell class="w-[40%] min-w-0">
                                    <div class="truncate">{{ $check->check_type.' '.$check->check_number }} · {{ $check->date->format('m/d/y') }}</div>
                                </flux:table.cell>
                                <flux:table.cell align="end" class="w-[23%] tabular-nums">
                                    <a wire:navigate.hover href="{{ route('checks.show', $check->id) }}" class="font-semibold text-red-700 dark:text-red-400">{{ money($check->amount) }}</a>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    @endforeach
                @endforeach
            </x-index-table.table>
        @endif
    </x-index-table>

    @if(!$onIndex)
        <flux:modal name="relink-bank-{{ $bank->id }}" class="max-w-md">
            <div class="space-y-4">
                <flux:heading size="lg">Reconnect {{ $bank->name }} as a new connection?</flux:heading>

                <flux:text>Use this when Update Bank Account can't fix the login. You sign in to {{ $bank->name }} through Plaid again, and:</flux:text>

                <ul class="list-disc pl-5 space-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                    <li>The accounts, transactions, checks and expense links stay where they are.</li>
                    <li>Transactions since the old connection last synced come in; anything already imported is skipped.</li>
                    <li>The old connection is removed at Plaid.</li>
                </ul>

                <div class="flex justify-end gap-2 pt-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" icon="arrow-path" wire:click="plaid_link_token_relink" x-on:click="$flux.modal('relink-bank-{{ $bank->id }}').close()">Continue to Plaid</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
