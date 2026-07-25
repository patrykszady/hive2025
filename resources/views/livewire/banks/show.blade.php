<div class="max-w-lg">
    <x-island-card :heading="$bank->name" :href="route('banks.show', $bank->id)">
        <x-slot:badge>
            <flux:badge inset="top bottom" color="{{$bank->error == FALSE ? 'green' : 'red'}}">{{$bank->error == FALSE ? 'Connected' : 'Error'}}</flux:badge>
        </x-slot:badge>
        <x-slot:actions>
            @if(!Route::is('banks.index'))
                @if($this->supportsStatements)
                    <flux:button wire:click="downloadLatestStatement" size="sm" variant="filled" icon="arrow-down-tray" wire:loading.attr="disabled" wire:target="downloadLatestStatement">Latest Statement</flux:button>
                @endif
                <flux:button wire:navigate.hover wire:click="plaid_link_token_update" size="sm">Update Bank Account</flux:button>
            @endif
            <div class="text-xs"><i>{{$bank->updated_at->diffForHumans()}}</i></div>
        </x-slot:actions>

        @if($bank->error)
            <flux:subheading class="text-red-800!">
                {{$bank->error['error_code']}}
            </flux:subheading>
        @endif

        @foreach($accounts as $bank_account_number => $bank_account_types)
            @foreach($bank_account_types as $bank_account_type => $bank_account_data)
                <flux:card class="space-y-2 p-2!">
                    <div class="flex justify-between">
                        <flux:heading size="lg">
                            {{$bank_account_number}}
                            <flux:badge inset="top bottom" size="sm" color="{{ $bank_account_data['account']->trashed() ? 'zinc' : 'blue' }}">{{$bank_account_type}}</flux:badge>
                        </flux:heading>
                        <div class="flex flex-col">
                            @if(isset($bank_account_data['account']->options->balances))
                                <flux:button variant="primary" disabled class="self-end">
                                    {{money(isset($bank_account_data['account']->options->balances->available) ? $bank_account_data['account']->options->balances->available : $bank_account_data['account']->options->balances->current ?? '')}}
                                </flux:button>
                            @endif
                            <div class="text-xs mt-1">
                                @if(isset($bank_account_data['account']->options->last_balance_update))
                                    <i>{{ \Carbon\Carbon::parse($bank_account_data['account']->options->last_balance_update)->diffForHumans() }}</i>
                                @endif
                            </div>
                        </div>
                    </div>
                    
                    @if($bank_account_data['checks']->isNotEmpty())
                        @foreach($bank_account_data['checks'] as $check)
                            <flux:card class="p-2!">
                                <div class="flex justify-between">
                                    <a wire:navigate.hover href="{{route('checks.show', $check->id)}}">
                                        <flux:heading>{{$check->owner}}</flux:heading>
                                        <flux:subheading>{{$check->check_type . ' ' . $check->check_number . ' ' . $check->date->format('m/d/Y')}}</flux:subheading>
                                    </a>
                                    <a wire:navigate.hover href="{{route('checks.show', $check->id)}}" class="text-red-800"><b>{{money($check->amount)}}</b></a>
                                </div>
                            </flux:card>
                        @endforeach
                    @endif
                </flux:card>
            @endforeach
        @endforeach
    </x-island-card>
</div>

