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

            <flux:callout color="indigo" icon="shield-check">
                <flux:callout.heading>Sign in without a password</flux:callout.heading>
                <flux:callout.text>
                    Passkeys let you sign in quickly and securely using your fingerprint, face, or device PIN. 
                    They're more secure than passwords and can't be phished.
                </flux:callout.text>
            </flux:callout>

            <div id="passkey-error" class="hidden">
                <flux:callout color="rose" icon="exclamation-triangle">
                    <flux:callout.heading id="passkey-error-heading"></flux:callout.heading>
                    <flux:callout.text id="passkey-error-text"></flux:callout.text>
                </flux:callout>
            </div>

            <div id="passkey-success" class="hidden">
                <flux:callout variant="success" icon="check-circle">
                    Passkey registered successfully! Redirecting...
                </flux:callout>
            </div>

            <div class="space-y-4">
                <flux:button type="button" variant="primary" class="w-full" id="register-passkey">
                    <span class="inline-flex items-center gap-2">
                        <flux:icon.finger-print class="w-5 h-5" />
                        <span id="register-passkey-text">Register Passkey</span>
                    </span>
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

<x-passkey-registration 
    button-id="register-passkey"
    button-text-id="register-passkey-text"
    :redirect-url="route('dashboard')"
/>