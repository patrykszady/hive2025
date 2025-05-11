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
            Livewire.dispatch(eventName, { item_data: metadata, bank_id: bankId });
        },

        onExit: function (err, metadata) {
            if (err) {
                console.error('Plaid API error:', err);

                Livewire.dispatch('plaidError', {
                    errorData: err,
                    bankId: bankId,
                });

                console.log('Dispatching plaidError event with data:', {
                    bank_id: bankId,
                    error_type: err.error_type || 'Unknown',
                    error_code: err.error_code || 'Unknown',
                    error_message: err.error_message || 'An unknown error occurred.',
                    display_message: err.display_message || 'No additional information provided.',
                    request_id: err.request_id || 'N/A',
                });
            }
        },
    });
}
