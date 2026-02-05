@props([
    'buttonId' => 'create-passkey-btn',
    'buttonTextId' => 'create-passkey-text',
    'errorId' => 'passkey-error',
    'errorHeadingId' => 'passkey-error-heading',
    'errorTextId' => 'passkey-error-text',
    'successId' => 'passkey-success',
    'redirectUrl' => null,
    'prepareMethod' => null,
    'completeMethod' => null,
    'failMethod' => null,
])

<script src="https://cdn.jsdelivr.net/npm/@laragear/webpass@2/dist/webpass.js" defer></script>
<script>
(function() {
    // Passkey registration debug logger - sends logs to server
    const passkeyLog = async (level, message, data = {}) => {
        const logEntry = {
            level,
            message,
            data,
            timestamp: new Date().toISOString(),
            url: window.location.href,
            userAgent: navigator.userAgent
        };
        console.log(`[PasskeyDebug] ${level}: ${message}`, data);
        try {
            await fetch('/api/passkey-debug-log', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                body: JSON.stringify(logEntry),
                credentials: 'include'
            }).catch(() => {}); // Ignore fetch errors
        } catch (e) { /* ignore */ }
    };

    const init = () => {
        const createBtn = document.getElementById('{{ $buttonId }}');
        const errorDiv = document.getElementById('{{ $errorId }}');
        const errorHeading = document.getElementById('{{ $errorHeadingId }}');
        const errorText = document.getElementById('{{ $errorTextId }}');
        const successDiv = document.getElementById('{{ $successId }}');
        const btnText = document.getElementById('{{ $buttonTextId }}');

        if (!createBtn || createBtn.dataset.bound === 'true') return;
        createBtn.dataset.bound = 'true';
        passkeyLog('info', 'Passkey registration component initialized');

        function getLivewireComponent() {
            // Find the closest Livewire component by walking up the DOM
            let el = createBtn;
            while (el) {
                if (el.hasAttribute && el.hasAttribute('wire:id')) {
                    return Livewire.find(el.getAttribute('wire:id'));
                }
                el = el.parentElement;
            }
            return null;
        }

        function showError(heading, text = null) {
            if (errorHeading && errorDiv) {
                errorHeading.textContent = heading;
                if (errorText) {
                    errorText.textContent = text || '';
                    errorText.classList.toggle('hidden', !text);
                }
                errorDiv.classList.remove('hidden');
            }
            if (successDiv) successDiv.classList.add('hidden');
        }

        function showSuccess() {
            if (successDiv) successDiv.classList.remove('hidden');
            if (errorDiv) errorDiv.classList.add('hidden');
        }

        function showNotCompletedMessage() {
            showError('Passkey setup was cancelled.', 'If you already have a passkey for this device, remove it from your device settings and try again, or use a different device.');
        }

        function resetButton() {
            createBtn.disabled = false;
            if (btnText) btnText.textContent = '{{ $buttonTextId === 'create-passkey-text' ? 'Create Passkey' : 'Register Passkey' }}';
        }

        createBtn.addEventListener('click', async function() {
            passkeyLog('info', 'Create passkey button clicked');
            if (errorDiv) errorDiv.classList.add('hidden');
            createBtn.disabled = true;
            if (btnText) btnText.textContent = 'Setting up...';

            const component = getLivewireComponent();
            if (!component) {
                passkeyLog('error', 'Could not find Livewire component');
                showError('An error occurred.', 'Please refresh and try again.');
                resetButton();
                return;
            }
            passkeyLog('info', 'Livewire component found');

            @if($prepareMethod)
            // First, prepare user on the server
            try {
                passkeyLog('info', 'Calling {{ $prepareMethod }}...');
                const result = await component.{{ $prepareMethod }}();
                passkeyLog('info', '{{ $prepareMethod }} result', { result });
                if (result !== true) {
                    passkeyLog('warn', 'Prepare method returned non-true value', { result });
                    resetButton();
                    return;
                }
                // Small delay to ensure session cookies are fully processed before WebAuthn call
                passkeyLog('info', 'Waiting 100ms for session cookies...');
                await new Promise(resolve => setTimeout(resolve, 100));
                passkeyLog('info', 'Cookie wait complete, proceeding to WebAuthn');
            } catch (e) {
                passkeyLog('error', 'Prepare method error', { error: e?.message || String(e), stack: e?.stack });
                showError('Failed to prepare registration.', 'Please try again.');
                resetButton();
                return;
            }
            @endif

            if (!window.Webpass) {
                passkeyLog('error', 'Webpass not loaded');
                showError('Passkeys are still loading.', 'Please try again in a moment.');
                resetButton();
                return;
            }
            passkeyLog('info', 'Webpass loaded, calling attest...');

            try {
                passkeyLog('info', 'Starting Webpass.attest');
                const { success, error } = await Webpass.attest(
                    { path: '/webauthn/register/options', credentials: 'include' },
                    { path: '/webauthn/register', credentials: 'include' }
                );
                passkeyLog('info', 'Webpass.attest completed', { success, error: error?.message || error?.name });
                
                if (success) {
                    passkeyLog('info', 'Passkey registration successful');
                    showSuccess();
                    @if($completeMethod)
                    passkeyLog('info', 'Calling {{ $completeMethod }}...');
                    await component.{{ $completeMethod }}();
                    passkeyLog('info', '{{ $completeMethod }} completed');
                    @elseif($redirectUrl)
                    passkeyLog('info', 'Redirecting to {{ $redirectUrl }}');
                    setTimeout(() => {
                        window.location.href = '{{ $redirectUrl }}';
                    }, 1500);
                    @endif
                    return;
                }

                passkeyLog('warn', 'Webpass.attest returned error', { errorName: error?.name, errorMessage: error?.message });
                if (error?.name === 'NotAllowedError' || error?.message?.includes('credentials creation was not completed')) {
                    passkeyLog('info', 'User cancelled or NotAllowedError');
                    showNotCompletedMessage();
                    @if($failMethod)
                    passkeyLog('info', 'Calling {{ $failMethod }}...');
                    await component.{{ $failMethod }}();
                    @endif
                } else {
                    passkeyLog('error', 'Unknown error from Webpass.attest', { error });
                    showError('Failed to register passkey.', error?.message || 'Please try again.');
                    @if($failMethod)
                    await component.{{ $failMethod }}();
                    @endif
                }
            } catch (exception) {
                passkeyLog('error', 'Passkey registration exception', { 
                    name: exception?.name, 
                    message: exception?.message, 
                    stack: exception?.stack,
                    raw: String(exception)
                });
                
                if (exception?.name === 'NotAllowedError' || exception?.message?.includes('credentials creation was not completed')) {
                    passkeyLog('info', 'Exception was NotAllowedError (user cancelled)');
                    showNotCompletedMessage();
                    @if($failMethod)
                    await component.{{ $failMethod }}();
                    @endif
                } else if (exception?.name === 'InvalidStateError') {
                    passkeyLog('warn', 'InvalidStateError - passkey already exists');
                    showError('Passkey already exists.', 'A passkey is already registered on this device.');
                } else {
                    passkeyLog('error', 'Unhandled exception type');
                    showError('Failed to register passkey.', exception?.message || 'Please try again.');
                    @if($failMethod)
                    await component.{{ $failMethod }}();
                    @endif
                }
            }
            
            resetButton();
        });
    };

    document.addEventListener('DOMContentLoaded', init);
    document.addEventListener('livewire:navigated', init);
})();
</script>
