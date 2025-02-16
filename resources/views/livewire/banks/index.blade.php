<div class="max-w-lg space-y-4">
    <flux:card>
        <div class="flex justify-between">
            <flux:heading size="lg">Transaction Accounts</flux:heading>
            <flux:button wire:navigate.hover wire:click="plaid_link_token" size="sm" icon="plus">New Bank Account</flux:button>
        </div>
        <flux:subheading size="md">Connect your Transactions to automatically match and organize with Expenses and Receipts.</flux:subheading>
    </flux:card>

    @foreach($this->banks as $bank)
        <livewire:banks.bank-show :bank="$bank" wire:key="{{$bank->id}}" />
    @endforeach

    {{-- PLAID LINK --}}
    <script>
        document.addEventListener('livewire:initialized', () => {
            @this.on('linkToken', exchangeToken => {
                var handler = Plaid.create({
                    token: exchangeToken,

                    onLoad: function() {
                        handler.open();
                    },

                    onSuccess: function(token, metadata) {
                        // console.log(metadata);
                        // Send the public_token to your app server.
                        // The metadata object contains info about the institution the
                        // user selected and the account ID or IDs, if the
                        // Select Account view is enabled.

                        //plaidLinkItem = plaid_link_item on BankIndex
                        @this.dispatch('plaidLinkItem', { item_data: metadata })
                    },

                    onExit: function(err, metadata) {
                        // The user exited the Link flow or error above.
                        if (err != null) {
                            // The user encountered a Plaid API error prior to exiting.
                        }
                            // metadata contains information about the institution
                            // that the user selected and the most recent API request IDs.
                            // Storing this information can be helpful for support.
                    },

                    onEvent: function(eventName, metadata) {
                        // Optionally capture Link flow events, streamed through
                        // this callback as your users connect an Item to Plaid.
                        // For example:
                        // eventName = "TRANSITION_VIEW"
                        // metadata  = {
                        //   link_session_id: "123-abc",
                        //   mfa_type:        "questions",
                        //   timestamp:       "2017-09-14T14:42:19.350Z",
                        //   view_name:       "MFA",
                        // }
                    }
                });
            });
        });
    </script>
    <script src="https://cdn.plaid.com/link/v2/stable/link-initialize.js"></script>
</div>
