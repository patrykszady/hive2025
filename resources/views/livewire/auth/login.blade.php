<div class="flex min-h-screen">
    <!-- Left side - Login form -->
    <div class="flex-1 flex justify-center items-center">
        <div class="w-96 max-w-96 space-y-6 p-4">
            @persist('auth-logo')
            <div class="flex justify-center opacity-90">
                <a href="{{ route('welcome') }}" wire:navigate class="group">
                    <img class="w-auto h-24 mx-auto" src="{{ asset('favicon.svg') }}" alt="{{ config('app.name') }}">
                </a>
            </div>
            @endpersist

            <flux:heading class="text-center" size="xl">Sign in to your Hive</flux:heading>

            @if(session('error'))
                <flux:callout color="indigo" icon="information-circle">
                    @if(is_array(session('error')))
                        <flux:callout.heading>{{ session('error')['heading'] }}</flux:callout.heading>
                        <flux:callout.text>{{ session('error')['text'] }}</flux:callout.text>
                    @else
                        <flux:callout.heading>{{ session('error') }}</flux:callout.heading>
                    @endif
                </flux:callout>
            @endif

            <div class="space-y-6">
                {{-- Step 1: Email only --}}
                @if($step === 'email')
                    <form wire:submit="checkEmail" class="space-y-6">
                        <flux:input 
                            wire:model.live.debounce.300ms="identifier" 
                            id="login-identifier"
                            label="Email or Phone"
                            type="text"
                            autocomplete="username webauthn"
                            placeholder="email@example.com or (555) 555-5555"
                            autofocus
                            required
                        />

                        <div wire:show="can_continue" wire:transition.opacity.duration.150ms wire:cloak>
                            <flux:button type="submit" variant="primary" class="w-full">
                                Continue
                            </flux:button>
                        </div>
                    </form>

                    <div class="space-y-3 -mt-2">
                        <flux:separator text="or" />

                        <flux:button 
                            href="{{ route('registration') }}"
                            wire:navigate
                            class="w-full"
                        >
                            Register
                        </flux:button>
                    </div>

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

                            <div class="space-y-3 -mt-2">
                                <flux:separator text="or" />

                                <div class="flex gap-2">
                                    <flux:button 
                                        type="button"
                                        wire:click="startOneTimeLogin"
                                        variant="outline"
                                        class="flex-1"
                                        icon="envelope" 
                                        icon:variant="outline"
                                    >
                                        One-time code
                                    </flux:button>

                                    @if($hasPassword)
                                        <flux:button 
                                            type="button"
                                            wire:click="showPasswordLogin"
                                            variant="outline"
                                            class="flex-1"
                                            icon="key" 
                                            icon:variant="outline"
                                        >
                                            Password
                                        </flux:button>
                                    @endif
                                </div>
                            </div>
                        @else
                            {{-- No passkey: show password form --}}
                            @if($hasPassword)
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

                                <div class="space-y-3 -mt-2">
                                    <flux:separator text="or" />

                                    <flux:button 
                                        type="button"
                                        wire:click="startOneTimeLogin"
                                        variant="outline"
                                        class="w-full"
                                    >
                                        <span class="inline-flex items-center justify-center gap-2">
                                            <flux:icon.envelope class="w-5 h-5" />
                                            <span>Use one-time code</span>
                                        </span>
                                    </flux:button>
                                </div>
                            @else
                                <div class="space-y-3">
                                    <flux:button 
                                        type="button"
                                        wire:click="startOneTimeLogin"
                                        variant="outline"
                                        class="w-full"
                                    >
                                        <span class="inline-flex items-center justify-center gap-2">
                                            <flux:icon.envelope class="w-5 h-5" />
                                            <span>Use one-time code</span>
                                        </span>
                                    </flux:button>
                                </div>
                            @endif
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Right side -->
    <div class="flex-1 p-4 max-lg:hidden">
        <div class="text-white relative rounded-lg h-full w-full bg-indigo-900 flex flex-col items-start justify-end p-16">
            <div class="mb-6">
                <div class="flex gap-4 mb-6">
                    <flux:icon.home variant="outline" class="size-16 text-indigo-300" />
                    <flux:icon.wrench-screwdriver variant="outline" class="size-16 text-indigo-300" />
                    <flux:icon.calendar-days variant="outline" class="size-16 text-indigo-300" />
                </div>
                <div class="text-lg text-indigo-100 leading-relaxed">
                    Pick up right where you left off. Your projects, your schedules, your updates — all here, ready to go.
                </div>
                <div class="text-lg text-indigo-100 leading-relaxed mt-4">
                    <strong class="text-white">Schedules, finances, estimates, updates</strong> — everything's in one place so you can hit the ground running.
                </div>
                <div class="text-lg text-indigo-100 leading-relaxed mt-4">
                    Welcome back — let's keep building.
                </div>
            </div>
        </div>
    </div>

@assets
<script src="https://cdn.jsdelivr.net/npm/@laragear/webpass@2/dist/webpass.js"></script>
@endassets
