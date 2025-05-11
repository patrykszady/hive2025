document.addEventListener('livewire:initialized', () => {
    Livewire.on('linkToken', exchangeToken => {
        initializePlaidLink(exchangeToken, 'plaidLinkItem');
    });

    Livewire.on('linkTokenUpdate', ({ exchangeToken, bankId }) => {
        initializePlaidLink(exchangeToken, 'plaidLinkItemUpdate', bankId);
    });
});

function initializePlaidLink(exchangeToken, eventName, bankId = null) {
    const handler = Plaid.create({
        token: exchangeToken,

        onLoad: function () {
            handler.open();
        },

        onSuccess: function (token, metadata) {
            console.log('Dispatching plaidLinkItem with metadata:', metadata);

            Livewire.dispatch(eventName, { item_data: metadata, bank_id: bankId });
        },

        onExit: function (err, metadata) {
            if (err) {
                console.error('Plaid API error:', err);

                Livewire.dispatch('plaidError', {
                    bank_id: bankId,
                    error_type: err.error_type,
                    error_code: err.error_code,
                    error_message: err.error_message,
                    display_message: err.display_message,
                    request_id: err.request_id,
                });

                Livewire.dispatch('toast', {
                    type: 'error',
                    message: `Plaid API Error: ${err.error_message || 'Unknown error occurred'}`,
                });
            }
        },
    });
}
