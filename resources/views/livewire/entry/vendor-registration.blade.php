<div x-data="{ finalizing: @js($registrationSubmitted) }">
    <div
        class="grid max-w-3xl grid-cols-1 gap-2 mx-auto mt-2 sm:px-6 lg:max-w-7xl lg:grid-cols-5 transition-[filter,transform,opacity] duration-500 ease-out"
        :class="finalizing ? 'blur-[2px] scale-[0.995] opacity-80 pointer-events-none select-none' : ''"
    >
        {{-- PROGRESS TIMELINE --}}
        <div class="space-y-4 lg:col-start-1 lg:col-span-2">
            <x-island-card heading="Hive Registration for {{$user->vendor->business_name}}" subheading="Registration Progress" :separator="true" class="sticky top-5">
                    <flux:field>
                        <div class="flex items-center gap-4" style="--flux-progress-percentage: {{ $this->getProgressValue() }}%">
                            <flux:progress :value="$this->getProgressValue()" color="indigo" />
                            <span class="text-sm tabular-nums text-zinc-500">{{ $this->getProgressValue() }}%</span>
                        </div>
                    </flux:field>

                    <flux:timeline class="mt-4">
                        {{-- Owner step (always complete) --}}
                        <flux:timeline.item status="complete">
                            <flux:timeline.indicator color="green">
                                <flux:icon.user variant="micro" />
                            </flux:timeline.indicator>
                            <flux:timeline.content>
                                <flux:heading size="sm">Owner <flux:text inline>{{ $user->full_name }}</flux:text> registration</flux:heading>
                            </flux:timeline.content>
                        </flux:timeline.item>

                        {{-- Dynamic Steps --}}
                        @foreach($this->getRegistrationSteps() as $index => $step)
                            @php $status = $this->getStepStatus($step['name']); @endphp
                            <flux:timeline.item wire:key="step-{{ $step['name'] }}" :status="$status">
                                <flux:timeline.indicator :color="$status === 'complete' ? 'green' : ($status === 'current' ? 'blue' : null)">
                                    <x-dynamic-component :component="'flux::icon.'.$step['icon']" variant="micro" />
                                </flux:timeline.indicator>
                                <flux:timeline.content>
                                    <flux:heading size="sm">
                                        @if($step['label']){{ $step['label'] }} @endif<flux:text inline>{{ $step['description'] }}</flux:text>
                                        @if($step['suffix']) <flux:text inline>{{ $step['suffix'] }}</flux:text>@endif
                                    </flux:heading>
                                </flux:timeline.content>
                            </flux:timeline.item>
                        @endforeach
                    </flux:timeline>
            </x-island-card>
        </div>
        
        {{-- REGISTRATION CONTENT --}}
        <div class="space-y-4 lg:col-start-3 lg:col-span-3 xl:col-span-2">
            {{-- VENDOR DETAILS --}}
            <livewire:vendors.vendor-details :vendor="$vendor" :view="$view" :expanded="true" />

            @if($vendor->business_type !== '1099')
                {{-- TEAM MEMBERS SECTION (non-1099 only) --}}
                <div x-show="$wire.registration.vendor_info" x-transition class="space-y-4" @style(['display: none' => empty($registration['vendor_info'])])>
                    <livewire:users.users-index 
                        :vendor="$vendor" 
                        :view="$view"
                    />

                    {{-- DISTRIBUTIONS & COMPANY EMAILS SECTION --}}
                    <div x-show="$wire.registration.team_members" x-transition class="space-y-4" @style(['display: none' => empty($registration['team_members'])])>
                        {{-- DISTRIBUTIONS - Show when company email exists --}}
                        @if($vendor->company_emails()->exists())
                            <livewire:distributions.distributions-list :view="$view" />
                        @endif
                        
                        <livewire:company-emails.company-emails-index :view="$view" />

                        @if($view === 'vendor_registration' && ! data_get($registration, 'emails_registered', false))
                            <div class="flex justify-end" x-data="{ isConfirmed: false }">
                                <flux:button
                                    variant="ghost"
                                    x-show="!isConfirmed"
                                    wire:click="confirmProcess('emails_registered')"
                                    x-on:click="$nextTick(() => { isConfirmed = true })"
                                >
                                    Skip
                                </flux:button>
                            </div>
                        @endif
                    </div>

                    {{-- BANKS / TRANSACTION ACCOUNTS SECTION --}}
                    <div x-show="$wire.registration.emails_registered" x-transition @style(['display: none' => empty($registration['emails_registered'])])>
                        <livewire:banks.bank-index :view="$view" />

                        @if($view === 'vendor_registration' && ! data_get($registration, 'banks_registered', false))
                            <div class="flex justify-end mt-4" x-data="{ isConfirmed: false }">
                                <flux:button
                                    variant="ghost"
                                    x-show="!isConfirmed"
                                    wire:click="confirmProcess('banks_registered')"
                                    x-on:click="$nextTick(() => { isConfirmed = true })"
                                >
                                    Skip
                                </flux:button>
                            </div>
                        @endif
                    </div>

                    {{-- FINAL REGISTRATION (after banks for non-1099) --}}
                    <div x-show="$wire.registration.banks_registered" x-transition @style(['display: none' => empty($registration['banks_registered'])])>
                        <form wire:submit="store" x-on:submit="finalizing = true; $flux.modal('finalizing-registration').show()">
                            <flux:button type="submit" variant="primary" class="w-full" :disabled="$registrationSubmitted">
                                Register {{$user->vendor->business_name}}
                            </flux:button>
                        </form>
                    </div>
                </div>
            @else
                {{-- 1099: Only vendor_info -> registered --}}
                <div x-show="$wire.registration.vendor_info" x-transition @style(['display: none' => empty($registration['vendor_info'])])>
                    <form wire:submit="store" x-on:submit="finalizing = true; $flux.modal('finalizing-registration').show()">
                        <flux:button type="submit" variant="primary" class="w-full" :disabled="$registrationSubmitted">
                            Register {{$user->vendor->business_name}}
                        </flux:button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    @if($registrationSubmitted)
        <div x-data x-init="$nextTick(() => { finalizing = true; $flux.modal('finalizing-registration').show() })"></div>
    @endif

    <div
        x-cloak
        x-show="finalizing"
        x-transition.opacity.duration.500ms
        class="fixed inset-0 z-40 bg-zinc-950/20 backdrop-blur-md"
    ></div>

    <flux:modal name="finalizing-registration" :closable="false" :dismissible="false" class="max-w-md z-50" wire:poll.250ms="refreshMatchingStatus">
        @if($matchingStatus === 'failed')
            <div class="space-y-4">
                <div class="flex items-center gap-3">
                    <flux:icon.exclamation-triangle class="size-6 text-red-500" />
                    <flux:heading size="lg">Something went wrong</flux:heading>
                </div>
                <flux:text>Matching failed. Please refresh, or contact support if it continues.</flux:text>
            </div>
        @else
            <div
                x-data="{
                    step: 0,
                    finished: false,
                    jobDone: false,
                    redirectUrl: '{{ route('dashboard') }}',
                    steps: [
                        'Finding your projects…',
                        'Importing checks…',
                        'Matching payments…',
                        'Preparing expenses…',
                        'Linking clients…',
                        'Building your dashboard…',
                    ],
                    interval: null,
                    stepDuration: 1600,
                    startedAt: Date.now(),
                    minDuration: 10000,
                    init() {
                        this.startSteps();

                        window.addEventListener('vendor-registration:complete', () => {
                            this.jobDone = true;
                            if (!this.interval) {
                                this.onCycleComplete();
                            }
                        });
                    },
                    startSteps() {
                        this.step = 0;
                        this.interval = setInterval(() => {
                            if (this.step < this.steps.length - 1) {
                                this.step++;
                            } else {
                                clearInterval(this.interval);
                                this.interval = null;
                                this.onCycleComplete();
                            }
                        }, this.stepDuration);
                    },
                    onCycleComplete() {
                        if (!this.jobDone) {
                            setTimeout(() => this.startSteps(), this.stepDuration);
                            return;
                        }

                        const elapsed = Date.now() - this.startedAt;
                        const remaining = this.minDuration - elapsed;

                        if (remaining > this.stepDuration * 2) {
                            setTimeout(() => this.startSteps(), this.stepDuration);
                        } else if (remaining > 0) {
                            setTimeout(() => this.finish(), remaining);
                        } else {
                            this.finish();
                        }
                    },
                    finish() {
                        this.finished = true;
                        if (this.interval) { clearInterval(this.interval); this.interval = null; }
                        this.step = this.steps.length;

                        setTimeout(() => {
                            sessionStorage.setItem('hub-unblur-transition', '1');
                            window.location.assign(this.redirectUrl);
                        }, 1500);
                    }
                }"
                class="space-y-6"
            >
                {{-- Header --}}
                <div class="text-center space-y-4">
                    <div
                        class="mx-auto flex items-center justify-center size-14 rounded-full bg-indigo-50 dark:bg-indigo-500/10 transition-all duration-500"
                    >
                        <svg
                            :class="finished ? '' : 'animate-spin'"
                            class="size-8 transition-all duration-500"
                            style="animation-duration: 3s"
                            viewBox="0 0 500 500"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <polyline points="377.03 255.61 436.16 288.39 436.16 357.47 363.78 399.26" fill="none" stroke="currentColor" class="text-indigo-600 dark:text-indigo-400" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
                            <polyline points="361.84 99.62 436.16 142.53 436.16 143.56 436.16 221.47" fill="none" stroke="currentColor" class="text-indigo-600 dark:text-indigo-400" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
                            <polyline points="179.66 75.68 250.01 35.06 361.84 99.62" fill="none" stroke="currentColor" class="text-indigo-600 dark:text-indigo-400" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
                            <polyline points="122.97 218.72 122.97 323.34 145.93 336.6" fill="none" stroke="currentColor" class="text-indigo-600 dark:text-indigo-400" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
                            <path d="M319.16,356.76c19.29-11.14,38.58-22.28,57.87-33.42v-24.55" fill="none" stroke="currentColor" class="text-indigo-600 dark:text-indigo-400" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
                            <path d="M129.64,180.51l120.37,70.53c7.19-4.15,14.38-8.3,21.57-12.45" fill="none" stroke="currentColor" class="text-indigo-600 dark:text-indigo-400" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
                            <path d="M179.51,424.24l70.5,40.7,68.7-39.98v-135.68s0,0,0,0h0c19.44-11.22,38.88-22.45,58.32-33.67v-78.95l-58.32-33.68-68.71-39.67-89.01,51.87" fill="none" stroke="currentColor" class="text-indigo-600 dark:text-indigo-400" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
                            <path d="M271.54,316.51c-7.18,4.14-14.35,8.29-21.53,12.43L63.86,221.47v-78.94l67.63-39.24,1.07-.73.27.16,59.11,34.12,126.77,73.19" fill="none" stroke="currentColor" class="text-indigo-600 dark:text-indigo-400" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
                            <path d="M63.86,283.06v74.41l68.7,39.85,58.59-33.82,58.85,33.19c7.22-4.17,14.44-8.34,21.66-12.5" fill="none" stroke="currentColor" class="text-indigo-600 dark:text-indigo-400" stroke-linecap="round" stroke-miterlimit="10" stroke-width="30"/>
                        </svg>
                    </div>
                    <flux:heading size="lg">
                        <span x-show="!finished" x-transition>Getting your Hub ready</span>
                        <span x-show="finished" x-transition x-cloak>Your Hub is ready!</span>
                    </flux:heading>
                </div>

                {{-- Steps --}}
                <div class="space-y-2">
                    <template x-for="(text, i) in steps" :key="i">
                        <div
                            class="flex items-center gap-3 rounded-lg px-3 py-2 transition-all duration-500"
                            :class="(i < step || finished) ? 'opacity-50' : (i === step ? 'bg-indigo-50 dark:bg-indigo-500/10' : 'opacity-30')"
                        >
                            <template x-if="i < step || finished">
                                <flux:icon.check class="size-5 text-indigo-500 shrink-0" />
                            </template>
                            <template x-if="i === step && !finished">
                                <div class="size-5 shrink-0 flex items-center justify-center">
                                    <div class="size-2 rounded-full bg-indigo-500 animate-pulse"></div>
                                </div>
                            </template>
                            <template x-if="i > step && !finished">
                                <div class="size-5 shrink-0"></div>
                            </template>
                            <span
                                class="text-sm transition-colors duration-500"
                                :class="i === step && !finished ? 'text-zinc-900 dark:text-white font-medium' : 'text-zinc-500 dark:text-zinc-400'"
                                x-text="text"
                            ></span>
                        </div>
                    </template>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
