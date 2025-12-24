<div>
    <div class="grid max-w-3xl grid-cols-1 gap-2 mx-auto mt-2 sm:px-6 lg:max-w-7xl lg:grid-cols-5">
        {{-- PROGRESS TIMELINE --}}
        <div class="space-y-4 lg:col-start-1 lg:col-span-2">
            <flux:card class="sticky top-5 space-y-4">
                <div>
                    <flux:heading size="lg">Hive Registration for {{$user->vendor->business_name}}</flux:heading>
                    <flux:subheading>Registration Progress</flux:subheading>
                </div>

                <flux:separator variant="subtle" />

                {{-- TIMELINE --}}
                <div class="flow-root">
                    <ul role="list" class="space-y-6">
                        {{-- User registration step (always complete) --}}
                        <x-vendor-registration.step 
                            status="completed"
                            icon="user"
                            label="Owner"
                            description="{{ $user->full_name }}"
                            suffix="registration"
                            :is-last="false"
                        />

                        {{-- Dynamic Steps --}}
                        @foreach($this->getRegistrationSteps() as $index => $step)
                            <x-vendor-registration.step 
                                :status="$this->getStepStatus($step['name'])"
                                :icon="$step['icon']"
                                :label="$step['label']"
                                :description="$step['description']"
                                :suffix="$step['suffix']"
                                :is-last="$index === count($this->getRegistrationSteps()) - 1"
                            />
                        @endforeach
                    </ul>
                </div>
            </flux:card>
        </div>
        
        {{-- REGISTRATION CONTENT --}}
        <div class="space-y-4 lg:col-start-3 lg:col-span-3 xl:col-span-2">
            {{-- VENDOR DETAILS --}}
            <livewire:vendors.vendor-details :vendor="$vendor" :view="$view" />

            @if($vendor->business_type !== '1099')
                {{-- TEAM MEMBERS SECTION (non-1099 only) --}}
                <div x-cloak x-show="$wire.registration.vendor_info" x-transition class="space-y-4">
                    <livewire:users.users-index 
                        :vendor="$vendor" 
                        :view="$view"
                    />

                    {{-- DISTRIBUTIONS & COMPANY EMAILS SECTION --}}
                    <div x-cloak x-show="$wire.registration.team_members" x-transition class="space-y-4">
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
                                    wire:click="confirmProcess('emails_registered'); $nextTick(() => { isConfirmed = true })"
                                >
                                    Skip
                                </flux:button>
                            </div>
                        @endif
                    </div>

                    {{-- BANKS / TRANSACTION ACCOUNTS SECTION --}}
                    <div x-cloak x-show="$wire.registration.emails_registered" x-transition>
                        <livewire:banks.bank-index :view="$view" />

                        @if($view === 'vendor_registration' && ! data_get($registration, 'banks_registered', false))
                            <div class="flex justify-end mt-4" x-data="{ isConfirmed: false }">
                                <flux:button
                                    variant="ghost"
                                    x-show="!isConfirmed"
                                    wire:click="confirmProcess('banks_registered'); $nextTick(() => { isConfirmed = true })"
                                >
                                    Skip
                                </flux:button>
                            </div>
                        @endif
                    </div>

                    {{-- FINAL REGISTRATION (after banks for non-1099) --}}
                    <div x-cloak x-show="$wire.registration.banks_registered" x-transition>
                        <form wire:submit="store">
                            <flux:button type="submit" variant="primary" class="w-full">
                                Register {{$user->vendor->business_name}}
                            </flux:button>
                        </form>
                    </div>
                </div>
            @else
                {{-- 1099: Only vendor_info -> registered --}}
                <div x-cloak x-show="$wire.registration.vendor_info" x-transition>
                    <form wire:submit="store">
                        <flux:button type="submit" variant="primary" class="w-full">
                            Register {{$user->vendor->business_name}}
                        </flux:button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    @if($registrationSubmitted)
        <div class="mx-auto mt-4 max-w-md">
            <flux:card class="space-y-3" wire:poll.250ms="refreshMatchingStatus">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg">Finalizing</flux:heading>
                    <flux:badge
                        inset="top bottom"
                        color="{{ $matchingStatus === 'completed' ? 'green' : ($matchingStatus === 'failed' ? 'red' : ($matchingStatus === 'processing' ? 'amber' : 'blue')) }}"
                    >
                        {{ $matchingStatus ?? 'queued' }}
                    </flux:badge>
                </div>

                @if($matchingStatus === 'failed')
                    <flux:text>
                        Matching failed. Please refresh, or contact support if it continues.
                    </flux:text>
                @elseif($matchingStatus === 'completed')
                    <flux:text>
                        Done. Redirecting to your dashboard…
                    </flux:text>
                @else
                    <flux:text>
                        We're matching your existing checks, clients, projects, and payments.
                    </flux:text>
                @endif
            </flux:card>
        </div>
    @endif
</div>
