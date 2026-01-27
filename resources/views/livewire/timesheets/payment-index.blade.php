<div class="max-w-md space-y-4">
    <flux:card class="space-y-2">
        <div class="flex justify-between">
            <flux:heading size="lg">Pay Team Members</flux:heading>

            {{-- <div>
                <flux:button variant="primary" disabled>
                    @php
                        $balances = collect($bank->plaid_options->accounts)->where('account_id', $account->plaid_account_id)->first();
                    @endphp

                    @if(isset($balances))
                        {{money(isset($balances->balances->available) ? $balances->balances->available : $balances->balances->current)}}
                    @else
                        "N/A"
                    @endif

                </flux:button>
                <div><i>{{$bank->updated_at->diffForHumans()}}</i></div>
            </div> --}}
        </div>

        <div class="space-y-2">
            @foreach($vendor_users as $user)
                <a href="{{route('timesheets.payment', $user->id)}}" class="block">
                    <flux:card class="hover:bg-indigo-100 hover:border-indigo-300">
                        <div class="flex justify-between">
                            <flux:heading>Pay {{$user->first_name}}</flux:heading>
                            <b class="text-indigo-800">{{money($user->total)}}</b>
                        </div>
                    </flux:card>
                </a>
            @endforeach
        </div>
    </flux:card>
</div>
