document.addEventListener('livewire:initialized', () => {
    Livewire.on('linkToken', exchangeToken => {
        initializePlaidLink(exchangeToken, 'plaidLinkItem');
    });

    Livewire.on('linkTokenUpdate', (payload) => {
        // Handle the array structure
        const data = Array.isArray(payload) ? payload[0] : payload;

        const exchangeToken = data.exchangeToken || data['exchangeToken'];
        const bankId = data.bankId || data['bankId'];

        if (!exchangeToken) {
            console.error('Error: exchangeToken is missing or invalid.');
            return;
        }

        initializePlaidLink(exchangeToken, 'plaidLinkItemUpdate', bankId);
    });
});

function initializePlaidLink(exchangeToken, eventName, bankId = null) {
    if (!exchangeToken) {
        console.error('Error: Missing exchangeToken for Plaid Link.');
        return;
    }

    const handler = Plaid.create({
        token: exchangeToken,

        onLoad: function () {
            console.log('Plaid Link loaded.');
            handler.open();
        },

        onSuccess: function (token, metadata) {
            console.log('Dispatching plaidLinkItem with metadata:', metadata);

            // Send the full payload the server expects
            Livewire.dispatch(eventName, {
                public_token: token,
                institution: metadata?.institution,
                accounts: metadata?.accounts,
                bank_id: bankId,
            });
        },

        onExit: function (err, metadata) {
            if (err) {
                Livewire.dispatch('plaidError', {
                    errorData: err,
                    bankId: bankId,
                });
            }
        },
    });
}
