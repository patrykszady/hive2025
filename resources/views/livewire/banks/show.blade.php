<div class="max-w-lg">
    <flux:card class="space-y-2">
        <div class="flex justify-between">
            <div>
                <flux:heading size="lg">
                    <a href="{{route('banks.show', $bank->id)}}">
                        {{$bank->name}}
                    </a>
                    <flux:badge inset="top bottom" color="{{$bank->error == FALSE ? 'green' : 'red'}}">{{$bank->error == FALSE ? 'Connected' : 'Error'}}</flux:badge>
                </flux:heading>

                @if($bank->error)
                    <flux:subheading class="text-red-800!">
                        {{$bank->error['error_code']}}
                    </flux:subheading>
                @endif
            </div>
            <div>
                @if(!Route::is('banks.index'))
                    <flux:button wire:navigate.hover wire:click="plaid_link_token_update" size="sm">Update Bank Account</flux:button>
                @endif
                <div class="text-xs"><i>{{$bank->updated_at->diffForHumans()}}</i></div>
            </div>
        </div>

        @foreach($accounts as $bank_account_number => $bank_account_types)
            @foreach($bank_account_types as $bank_account_type => $bank_account_checks)
                <flux:card class="space-y-2 p-2!">
                    <div class="flex justify-between">
                        <flux:heading size="lg">
                            {{$bank_account_number}}
                            <flux:badge inset="top bottom" size="sm" color="sky">{{$bank_account_type}}</flux:badge>
                        </flux:heading>
                        {{-- <div>
                            <flux:button variant="primary" disabled class="float-right">
                                @php
                                    $balances = collect($bank->plaid_options->accounts)->where('account_id', $account->plaid_account_id)->first();
                                @endphp

                                @if(isset($balances))
                                    {{money(isset($balances->balances->available) ? $balances->balances->available : $balances->balances->current)}}
                                @else
                                    "N/A"
                                @endif
                            </flux:button>
                        </div> --}}
                    </div>
                    @if($bank_account_checks->isNotEmpty())
                        @foreach($bank_account_checks as $check)
                            <flux:card class="p-2!">
                                <div class="flex justify-between">
                                    <a href="{{route('checks.show', $check->id)}}">
                                        <flux:heading>{{$check->owner}}</flux:heading>
                                        <flux:subheading>{{$check->check_type . ' ' . $check->check_number . ' ' . $check->date->format('m/d/Y')}}</flux:subheading>
                                    </a>
                                    <a href="{{route('checks.show', $check->id)}}" class="text-red-800"><b>{{money($check->amount)}}</b></a>
                                </div>
                            </flux:card>
                        @endforeach
                    @endif
                </flux:card>
            @endforeach
        @endforeach
    </flux:card>
</div>

