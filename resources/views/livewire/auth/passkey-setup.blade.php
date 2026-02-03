<div class="flex min-h-screen">
    <!-- Left side - Passkey Setup form -->
    <div class="flex-1 flex justify-center items-center">
        <div class="w-96 max-w-96 space-y-6 p-4">
            <div class="flex justify-center opacity-90">
                <a href="{{ route('welcome') }}" wire:navigate class="group">
                    <img class="w-auto h-24 mx-auto" src="{{ asset('favicon.png') }}" alt="{{ config('app.name') }}">
                </a>
            </div>

            <flux:heading class="text-center" size="xl">Set Up a Passkey</flux:heading>

            <flux:callout color="sky" icon="shield-check">
                <flux:callout.heading>Sign in without a password</flux:callout.heading>
                <flux:callout.text>
                    Passkeys let you sign in quickly and securely using your fingerprint, face, or device PIN. 
                    They're more secure than passwords and can't be phished.
                </flux:callout.text>
            </flux:callout>

            <div id="passkey-error" class="hidden">
                <flux:callout color="red" icon="exclamation-triangle">
                    <span id="passkey-error-text"></span>
                </flux:callout>
            </div>

            <div id="passkey-success" class="hidden">
                <flux:callout color="green" icon="check-circle">
                    Passkey registered successfully! Redirecting...
                </flux:callout>
            </div>

            <div class="space-y-4">
                <flux:button type="button" variant="primary" class="w-full" id="register-passkey">
                    <flux:icon.finger-print class="w-5 h-5 mr-2" />
                    Register Passkey
                </flux:button>

                <flux:button type="button" variant="ghost" class="w-full" wire:click="skip">
                    Skip for now
                </flux:button>
            </div>

            <div class="text-center text-sm text-zinc-500 dark:text-zinc-400">
                <p>You can always set up a passkey later in your account settings.</p>
            </div>
        </div>
    </div>

    <!-- Right side - Decorative (optional, matches login page) -->
    <div class="hidden lg:flex flex-1 items-center justify-center bg-zinc-100 dark:bg-zinc-800/50">
        <div class="max-w-md p-8 text-center">
            <flux:icon.shield-check class="w-24 h-24 mx-auto text-indigo-500 mb-6" />
            <h2 class="text-2xl font-bold text-zinc-800 dark:text-zinc-200 mb-4">
                Passwordless Authentication
            </h2>
            <p class="text-zinc-600 dark:text-zinc-400">
                Passkeys use biometrics or your device's secure element to authenticate you. 
                They're resistant to phishing and eliminate the need to remember passwords.
            </p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@laragear/webpass@2/dist/webpass.js" defer></script>
<script>
(function() {
    const init = () => {
        const registerBtn = document.getElementById('register-passkey');
        const errorDiv = document.getElementById('passkey-error');
        const errorText = document.getElementById('passkey-error-text');
        const successDiv = document.getElementById('passkey-success');

        if (!registerBtn || registerBtn.dataset.bound === 'true') return;
        registerBtn.dataset.bound = 'true';

        function showError(message) {
            errorText.textContent = message;
            errorDiv.classList.remove('hidden');
            successDiv.classList.add('hidden');
        }

        function showSuccess() {
            successDiv.classList.remove('hidden');
            errorDiv.classList.add('hidden');
        }

        function showNotCompletedMessage() {
            showError('Passkey setup was cancelled. You can try again or skip for now.');
        }

        registerBtn.addEventListener('click', async function() {
            errorDiv.classList.add('hidden');
            registerBtn.disabled = true;
            registerBtn.textContent = 'Setting up...';

            if (!window.Webpass) {
                showError('Passkeys are still loading. Please try again in a moment.');
                registerBtn.disabled = false;
                registerBtn.textContent = 'Register Passkey';
                return;
            }

            try {
                const { success, error } = await Webpass.attest('/webauthn/register/options', '/webauthn/register');
                
                if (success) {
                    showSuccess();
                    setTimeout(() => {
                        window.location.href = '{{ route("dashboard") }}';
                    }, 1500);
                    return;
                }

                if (error?.name === 'NotAllowedError' || error?.message?.includes('credentials creation was not completed')) {
                    showNotCompletedMessage();
                } else {
                    showError(error?.message || 'Failed to register passkey. Please try again.');
                }
            } catch (exception) {
                console.error('Passkey registration error:', exception);
                
                if (exception?.name === 'NotAllowedError' || exception?.message?.includes('credentials creation was not completed')) {
                    showNotCompletedMessage();
                } else if (exception?.name === 'InvalidStateError') {
                    showError('A passkey is already registered on this device.');
                } else {
                    showError(exception?.message || 'Failed to register passkey. Please try again.');
                }
            }
            
            registerBtn.disabled = false;
            registerBtn.innerHTML = '<svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4"></path></svg> Register Passkey';
        });
    };

    document.addEventListener('DOMContentLoaded', init);
    document.addEventListener('livewire:navigated', init);
})();
</script>
