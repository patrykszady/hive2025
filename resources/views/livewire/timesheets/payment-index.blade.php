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

        @foreach($vendor_users as $user)
            <flux:card class="hover:bg-sky-100 hover:border-sky-300">
                <div class="flex justify-between">
                    <a href="{{route('timesheets.payment', $user->id)}}">
                        <flux:heading>Pay {{$user->first_name}}</flux:heading>
                        {{-- <flux:subheading>{{$check->check_type . ' ' . $check->check_number . ' ' . $check->date->format('m/d/Y')}}</flux:subheading> --}}
                    </a>
                    <a href="{{route('timesheets.payment', $user->id)}}" class="text-sky-800"><b>{{money($user->total)}}</b></a>
                </div>
            </flux:card>
        @endforeach
    </flux:card>
</div>
