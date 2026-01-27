<div class="flex min-h-screen">
    <!-- Left side - Registration form -->
    <div class="flex-1 flex justify-center items-center">
        <div class="w-96 max-w-96 space-y-6 p-4">
            <div class="flex justify-center opacity-90">
                <a href="{{ route('welcome') }}" wire:navigate class="group">
                    <img class="w-auto h-24 mx-auto" src="{{ asset('favicon.png') }}" alt="{{ config('app.name') }}">
                </a>
            </div>

            <flux:heading class="text-center" size="xl">Register your Hive</flux:heading>

            <div class="space-y-6">
                {{-- CELL PHONE --}}
                @if($step === 'phone')
                <div class="space-y-6">
                    <flux:input 
                        wire:model.live.debounce.500ms="user_cell"
                        label="Cell Phone Number"
                        type="tel"
                        placeholder="(555) 555-5555"
                        mask="(999) 999-9999"
                        required
                    />

                    @if($user_cell_valid)
                        <flux:button 
                            wire:click="user_cell_confirm"
                            variant="primary" 
                            class="w-full"
                        >
                            Confirm Number
                        </flux:button>
                    @endif
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
                                value="{{ $user->email }}"
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

                    <flux:input 
                        wire:model.live.debounce.1000ms="password"
                        label="New Password"
                        type="password"
                        placeholder="Your password"
                        required
                    />

                    <flux:input 
                        wire:model.live.debounce.1000ms="password_confirmation"
                        label="Password Confirmation"
                        type="password"
                        placeholder="Confirm your password"
                        required
                    />

                    <flux:button type="submit" variant="primary" class="w-full">
                        Register
                    </flux:button>
                </form>
                @endif
            </div>

            @if($step === 'phone')
            <flux:separator text="or"/>

            <flux:button 
                href="{{ route('login') }}"
                wire:navigate
                class="w-full"
            >
                Sign In
            </flux:button>
            @endif
        </div>
    </div>

    <!-- Right side - Testimonial -->
    <div class="flex-1 p-4 max-lg:hidden">
        <div class="text-white relative rounded-lg h-full w-full bg-indigo-900 flex flex-col items-start justify-end p-16">
            <div class="flex gap-2 mb-4">
                <flux:icon.star variant="solid" />
                <flux:icon.star variant="solid" />
                <flux:icon.star variant="solid" />
                <flux:icon.star variant="solid" />
                <flux:icon.star variant="solid" />
            </div>

            <div class="mb-6 italic font-base text-3xl xl:text-4xl">
                "Registering my Hive was quick and easy. Now I have complete control over my projects and subcontractors."
            </div>

            <div class="flex gap-4">
                <flux:avatar src="{{ asset('favicon.png') }}" size="xl" />

                <div class="flex flex-col justify-center font-medium">
                    <div class="text-lg">Sarah Johnson</div>
                    <div class="text-zinc-300">Project Manager</div>
                </div>
            </div>
        </div>
    </div>
</div>




