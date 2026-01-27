<div class="flex min-h-screen">
    <!-- Left side - Form -->
    <div class="flex-1 flex justify-center items-center">
        <div class="w-96 max-w-96 space-y-6 p-4">
            <div class="flex justify-center opacity-90">
                <a href="{{ route('welcome') }}" wire:navigate class="group">
                    <img class="w-auto h-24 mx-auto" src="{{ asset('favicon.png') }}" alt="{{ config('app.name') }}">
                </a>
            </div>

            <flux:heading class="text-center" size="xl">One-Time Code Login</flux:heading>

            @if($success_message !== '')
                <div x-data="{ open: true }" x-init="setTimeout(() => open = false, 10000)" x-show="open" x-transition.opacity>
                    <flux:callout color="green" icon="check-circle" class="text-green-700 dark:text-green-200">
                        <div class="flex items-start justify-between gap-4">
                            <div>{{ $success_message }}</div>
                            <button type="button" class="text-zinc-500 hover:text-zinc-900" x-on:click="open = false" aria-label="Dismiss">
                                &times;
                            </button>
                        </div>
                    </flux:callout>
                </div>
            @endif

            @if($errors->any())
                <flux:callout color="red" icon="exclamation-triangle" class="text-red-700 dark:text-red-200">
                    {{ $errors->first() }}
                </flux:callout>
            @endif

            <div class="space-y-6">
                @if($step === 'request')
                    <form wire:submit="sendCode" class="space-y-6">
                        <flux:input
                            wire:model="email"
                            label="Email"
                            type="email"
                            autocomplete="username"
                            placeholder="email@example.com"
                            autofocus
                            required
                        />

                        <flux:button type="submit" variant="primary" class="w-full">
                            Send code
                        </flux:button>
                    </form>

                    <flux:separator text="or"/>

                    <flux:button 
                        href="{{ route('login') }}"
                        wire:navigate
                        variant="outline"
                        class="w-full"
                    >
                        Back to Login
                    </flux:button>
                @else
                    <p class="text-sm text-zinc-600 text-center">
                        We sent a 6-digit code to <strong>{{ $email }}</strong>
                    </p>

                    <form wire:submit="verifyCode" class="space-y-6">
                        <flux:field class="text-center">
                            <flux:label>Verification Code</flux:label>
                            <div class="flex justify-center">
                                <flux:otp wire:model="verification_code" length="6" autocomplete="one-time-code" autofocus />
                            </div>
                            <flux:error name="verification_code" />
                        </flux:field>

                        <flux:button type="submit" variant="primary" class="w-full">
                            Verify & Sign In
                        </flux:button>
                    </form>

                    <div class="text-center">
                        @if($can_resend)
                            <flux:button variant="outline" wire:click="resendCode" class="w-full">
                                Resend Code
                            </flux:button>
                        @else
                            <div
                                x-data="{ countdown: @entangle('resend_countdown') }"
                                x-init="
                                    let interval = setInterval(() => {
                                        if (countdown > 0) {
                                            countdown--;
                                        } else {
                                            clearInterval(interval);
                                            $wire.enableResend();
                                        }
                                    }, 1000);
                                "
                            >
                                <flux:button variant="outline" class="w-full" disabled>
                                    <span x-text="`Resend code in ${countdown || 0}s`"></span>
                                </flux:button>
                            </div>
                        @endif
                    </div>

                    <flux:separator text="or"/>

                    <flux:button 
                        href="{{ route('login') }}"
                        wire:navigate
                        variant="outline"
                        class="w-full"
                    >
                        Back to Login
                    </flux:button>
                @endif
            </div>
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
                "Hive has transformed how we organize our projects and collaborate with our subcontractors."
            </div>

            <div class="flex gap-4">
                <flux:avatar src="{{ asset('favicon.png') }}" size="xl" />

                <div class="flex flex-col justify-center font-medium">
                    <div class="text-lg">Grzegorz Szady</div>
                    <div class="text-zinc-300">Boss</div>
                </div>
            </div>
        </div>
    </div>
</div>
