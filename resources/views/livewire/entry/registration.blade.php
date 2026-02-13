<div class="flex min-h-screen" wire:cloak>
    <!-- Left side - Registration form -->
    <div class="flex-1 flex justify-center items-center">
        <div class="w-96 max-w-96 space-y-6 p-4">
            @persist('auth-logo')
            <div class="flex justify-center opacity-90">
                <a href="{{ route('welcome') }}" wire:navigate class="group">
                    <img class="w-auto h-24 mx-auto" src="{{ asset('favicon.svg') }}" alt="{{ config('app.name') }}">
                </a>
            </div>
            @endpersist

            <flux:heading class="text-center" size="xl">Register for Hive</flux:heading>

            @if($show_unregistered_notice && $step !== 'phone')
                <flux:callout color="indigo" icon="information-circle">
                    <flux:callout.heading>Number not registered</flux:callout.heading>
                    <flux:callout.text>
                        This number isn’t registered yet. Create your Hive to continue.
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div class="space-y-6">
                {{-- CELL PHONE --}}
                @if($step === 'phone')
                    <div class="space-y-6">
                        <flux:field>
                            <flux:label>Cell Phone Number</flux:label>
                            <flux:input 
                                wire:model.live.debounce.1000ms="user_cell"
                                type="tel"
                                placeholder="(555) 555-5555"
                                mask="(999) 999-9999"
                                required
                                :loading="false"
                            />
                            <flux:error name="user_cell" wire:transition />
                        </flux:field>

                        <div wire:show="can_confirm_user_cell" wire:transition wire:cloak>
                            <flux:button 
                                wire:click="confirmUserCellAction"
                                variant="primary" 
                                class="w-full"
                            >
                                Confirm Number
                            </flux:button>
                        </div>
                    </div>
                @endif

                {{-- CELL VERIFICATION CODE --}}
                @if($step === 'verify-phone')
                <flux:card>
                    <form wire:submit.prevent="cell_verification_code_confirm" class="space-y-8">
                        <div class="max-w-64 mx-auto space-y-2">
                            <flux:heading size="lg" class="text-center">Verify your phone</flux:heading>
                            <flux:text class="text-center">Please enter the 6-digit code we texted you.</flux:text>
                        </div>

                        <flux:otp wire:model.live="cell_verification_code" length="6" label="Verification Code" label:sr-only :error:icon="false" error:class="text-center" class="mx-auto">
                            <flux:otp.input />
                            <flux:otp.input />
                            <flux:otp.input />
                            <flux:otp.separator />
                            <flux:otp.input />
                            <flux:otp.input />
                            <flux:otp.input />
                        </flux:otp>

                        <div class="space-y-4">
                            <flux:button variant="primary" type="submit" class="w-full">Verify</flux:button>
                            <flux:button 
                                wire:click="resendPhoneCode" 
                                class="w-full"
                                :disabled="!$this->canResendPhone()"
                                wire:poll.1s
                            >
                                @if($this->canResendPhone())
                                    Resend code
                                @else
                                    Resend code ({{ $this->phoneResendCountdown() }}s)
                                @endif
                            </flux:button>
                        </div>
                    </form>
                </flux:card>
                @endif

                {{-- USER EMAIL --}}
                @if($step === 'email')
                <div class="space-y-6">
                    @if($this->hasExistingEmail())
                        <flux:field>
                            <flux:label>Email Address</flux:label>
                            <flux:input 
                                value="{{ $this->maskedEmail() }}"
                                type="email"
                                disabled
                            />
                            <flux:description>We found this email associated with your phone number.</flux:description>
                        </flux:field>
                    @else
                        <flux:field>
                            <flux:label>Email Address</flux:label>
                            <flux:input 
                                wire:model.live.debounce.1000ms="user.email"
                                type="email"
                                placeholder="email@example.com"
                                required
                            />
                            <flux:description><strong>Personal email</strong> NOT your business email.</flux:description>
                        </flux:field>
                    @endif

                    <flux:button 
                        wire:click="user_email"
                        variant="primary" 
                        class="w-full"
                    >
                        Confirm Email
                    </flux:button>
                </div>
                @endif

                {{-- EMAIL VERIFICATION CODE --}}
                @if($step === 'verify-email')
                <flux:card>
                    <form wire:submit.prevent="email_verification_code_confirm" class="space-y-8">
                        <div class="max-w-64 mx-auto space-y-2">
                            <flux:heading size="lg" class="text-center">Verify your email</flux:heading>
                            <flux:text class="text-center">Please enter the 6-digit code we emailed you.</flux:text>
                        </div>

                        <flux:otp wire:model.live="email_verification_code" length="6" label="Verification Code" label:sr-only :error:icon="false" error:class="text-center" class="mx-auto">
                            <flux:otp.input />
                            <flux:otp.input />
                            <flux:otp.input />
                            <flux:otp.separator />
                            <flux:otp.input />
                            <flux:otp.input />
                            <flux:otp.input />
                        </flux:otp>

                        <div class="space-y-4">
                            <flux:button variant="primary" type="submit" class="w-full">Verify</flux:button>
                            <flux:button 
                                wire:click="resendEmailCode" 
                                class="w-full"
                                :disabled="!$this->canResendEmail()"
                                wire:poll.1s
                            >
                                @if($this->canResendEmail())
                                    Resend code
                                @else
                                    Resend code ({{ $this->emailResendCountdown() }}s)
                                @endif
                            </flux:button>
                        </div>
                    </form>
                </flux:card>
                @endif

                {{-- USER NAMES AND PASSWORD --}}
                @if($step === 'complete')
                <form 
                    wire:submit="register_user"
                    class="space-y-6"
                >
                    @if($this->hasExistingEmail() && $user->first_name)
                        <flux:input 
                            value="{{ $user->first_name }}"
                            label="First Name"
                            disabled
                        />
                    @else
                        <flux:input 
                            wire:model.live.debounce.1000ms="user.first_name"
                            label="First Name"
                            placeholder="John"
                            required
                        />
                    @endif

                    @if($this->hasExistingEmail() && $user->last_name)
                        <flux:input 
                            value="{{ $user->last_name }}"
                            label="Last Name"
                            disabled
                        />
                    @else
                        <flux:input 
                            wire:model.live.debounce.1000ms="user.last_name"
                            label="Last Name"
                            placeholder="Doe"
                            required
                        />
                    @endif

                    {{-- Passkey option --}}
                    <div wire:show="!$wire.use_password" wire:transition.opacity.duration.150ms wire:cloak class="space-y-6">
                        <x-passkey-benefits-callout />

                        <div id="passkey-error" class="hidden">
                            <flux:callout color="rose" icon="exclamation-triangle">
                                <flux:callout.heading id="passkey-error-heading"></flux:callout.heading>
                                <flux:callout.text id="passkey-error-text"></flux:callout.text>
                            </flux:callout>
                        </div>

                        <div id="passkey-success" class="hidden">
                            <flux:callout color="green" icon="check-circle">
                                <flux:callout.heading class="text-green-700 dark:text-green-300">Passkey registered successfully! Redirecting...</flux:callout.heading>
                            </flux:callout>
                        </div>

                        <div class="space-y-3 -mt-2">
                            <flux:button type="button" variant="primary" class="w-full" id="create-passkey-btn">
                                <span class="inline-flex items-center gap-2">
                                    <flux:icon.finger-print class="w-5 h-5" />
                                    <span id="create-passkey-text">Create Passkey</span>
                                </span>
                            </flux:button>

                            <flux:separator text="or" />

                            <flux:button type="button" variant="outline" class="w-full" wire:click="showPasswordOption">
                                Use Password
                            </flux:button>
                        </div>
                    </div>

                    {{-- Password option --}}
                    <div wire:show="$wire.use_password" wire:transition.opacity.duration.150ms wire:cloak class="space-y-6">
                        <flux:input 
                            wire:model.live.debounce.500ms="password"
                            label="New Password"
                            type="password"
                            placeholder="Your password"
                        />

                        <flux:input 
                            wire:model.live.debounce.500ms="password_confirmation"
                            label="Password Confirmation"
                            type="password"
                            placeholder="Confirm your password"
                        />

                        <div wire:show="$wire.passwords_ready" wire:transition.opacity.duration.150ms>
                            <flux:button type="submit" variant="primary" class="w-full">
                                Register
                            </flux:button>
                        </div>

                        <div class="space-y-3 -mt-2">
                            <flux:separator text="or" />

                            <flux:button type="button" variant="outline" class="w-full" wire:click="showPasskeyOption">
                                <span class="inline-flex items-center gap-2">
                                    <flux:icon.finger-print class="w-5 h-5" />
                                    <span>Use Passkey</span>
                                </span>
                            </flux:button>
                        </div>
                    </div>
                </form>
                @endif
            </div>

            @if($step === 'phone')
            <div class="space-y-3 -mt-2">
                <flux:separator text="or" />

                <flux:button 
                    href="{{ route('login') }}"
                    wire:navigate
                    class="w-full"
                >
                    Sign In
                </flux:button>
            </div>
            @endif
        </div>
    </div>

    @if($step === 'complete')
        <x-passkey-right-panel />
    @else
        <x-hive-right-panel />
    @endif

    <x-passkey-registration 
        button-id="create-passkey-btn"
        button-text-id="create-passkey-text"
        prepare-method="prepareUserForPasskey"
        complete-method="completePasskeyRegistration"
        fail-method="cancelPasskeyRegistration"
    />
</div>