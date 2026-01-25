<div class="flex min-h-screen">
    <!-- Left side - Login form -->
    <div class="flex-1 flex justify-center items-center">
        <div class="w-96 max-w-96 space-y-6 p-4">
            <div class="flex justify-center opacity-90">
                <a href="{{ route('welcome') }}" wire:navigate class="group">
                    <img class="w-auto h-24 mx-auto" src="{{ asset('favicon.png') }}" alt="{{ config('app.name') }}">
                </a>
            </div>

            <flux:heading class="text-center" size="xl">Sign in to your Hive</flux:heading>

            @if(session('error'))
                <flux:callout color="sky" icon="information-circle">
                    {{ session('error') }}
                </flux:callout>
            @endif

            <div class="space-y-6">
                {{-- Step 1: Email only --}}
                @if($step === 'email')
                    <form wire:submit="checkEmail" class="space-y-6">
                        <flux:input 
                            wire:model="email" 
                            id="login-email"
                            label="Email"
                            type="email"
                            autocomplete="username webauthn"
                            placeholder="email@example.com"
                            autofocus
                            required
                        />

                        <flux:button type="submit" variant="primary" class="w-full">
                            Continue
                        </flux:button>
                    </form>

                    <flux:separator text="or"/>

                    <flux:button 
                        href="{{ route('registration') }}"
                        wire:navigate
                        class="w-full"
                    >
                        Register
                    </flux:button>

                {{-- Step 2: Credentials (password or passkey) --}}
                @else
                    <div class="space-y-4">
                        <div class="flex items-center gap-2">
                            <flux:button variant="ghost" size="sm" wire:click="goBack" icon="arrow-left" />
                            <span class="text-sm text-zinc-600">{{ $email }}</span>
                        </div>

                        @if($hasPasskey)
                            {{-- User has passkey: show passkey login first --}}
                            <div class="space-y-4"
                                x-data="{
                                    email: @js($email),
                                    remember: $wire.entangle('remember'),
                                    error: null,
                                    loading: false,
                                    init() {
                                        if (window.Webpass && Webpass.isUnsupported()) {
                                            this.error = 'Passkeys are not supported on this device.';
                                        }
                                    },
                                    async login() {
                                        this.error = null;
                                        this.loading = true;
                                        if (!window.Webpass) {
                                            this.error = 'Passkeys are still loading. Try again.';
                                            this.loading = false;
                                            return;
                                        }
                                        try {
                                            const { success, error } = await Webpass.assert({
                                                path: '/webauthn/login/options',
                                                body: { email: this.email },
                                            }, {
                                                path: '/webauthn/login',
                                                body: { remember: this.remember ? 'on' : '' },
                                            });
                                            if (success) {
                                                window.location.href = '{{ route('dashboard') }}';
                                                return;
                                            }
                                            if (error?.name === 'NotAllowedError') {
                                                this.error = 'Passkey request was cancelled or no matching passkey was found.';
                                            } else {
                                                this.error = error?.message || 'Passkey login failed.';
                                            }
                                        } catch (e) {
                                            this.error = 'Passkey login failed.';
                                        }
                                        this.loading = false;
                                    }
                                }"
                            >
                                <flux:button 
                                    type="button" 
                                    variant="primary" 
                                    class="w-full" 
                                    x-on:click="login()"
                                    x-bind:disabled="loading"
                                >
                                    <flux:icon.finger-print class="w-5 h-5 mr-2" />
                                    <span x-show="!loading">Sign in with Passkey</span>
                                    <span x-show="loading" x-cloak>Authenticating...</span>
                                </flux:button>
                                <div x-show="error" x-text="error" x-cloak class="text-sm text-red-600"></div>

                                <flux:switch x-model="remember" label="Remember Me" align="left" />
                            </div>

                            <flux:separator text="or"/>

                            <flux:button 
                                type="button"
                                wire:click="startOneTimeLogin"
                                variant="outline"
                                class="w-full"
                                icon="envelope" 
                                icon:variant="outline"
                            >
                                Use one-time code
                            </flux:button>
                        @else
                            {{-- No passkey: show password form --}}
                            <form wire:submit="login" class="space-y-6">
                                <flux:field>
                                    <div class="mb-3 flex justify-between">
                                        <flux:label>Password</flux:label>
                                    </div>
                                    <flux:input 
                                        wire:model="password" 
                                        type="password"
                                        placeholder="Your password"
                                        autofocus
                                        required
                                    />
                                </flux:field>

                                <flux:switch wire:model.live="remember" label="Remember Me" align="left" />

                                <flux:button type="submit" variant="primary" class="w-full">
                                    Sign in
                                </flux:button>
                            </form>

                            <flux:separator text="or"/>

                            <flux:button 
                                type="button"
                                wire:click="startOneTimeLogin"
                                variant="outline"
                                class="w-full"
                            >
                                <flux:icon.envelope class="w-5 h-5 mr-2" />
                                Use one-time code
                            </flux:button>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Right side - Testimonial -->
    <div class="flex-1 p-4 max-lg:hidden">
        <div class="text-white relative rounded-lg h-full w-full bg-blue-900 flex flex-col items-start justify-end p-16">
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

@assets
<script src="https://cdn.jsdelivr.net/npm/@laragear/webpass@2/dist/webpass.js"></script>
@endassets
