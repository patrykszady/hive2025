<div>
    @if($showPasskeyPrompt)
        <div x-data x-init="$nextTick(() => $flux.modal('passkey-setup-reminder').show())"></div>

        <flux:modal name="passkey-setup-reminder" class="max-w-lg">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">Set up a passkey</flux:heading>
                    <flux:text class="mt-2">
                        <p>Password sign-in is going away. Set up a passkey to keep access fast and secure.</p>
                    </flux:text>
                </div>

                <div class="flex items-center gap-2">
                    <flux:spacer />
                    <flux:modal.close>
                        <flux:button variant="ghost">Not now</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" href="{{ route('passkey.setup') }}" wire:navigate>
                        Set up passkey
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    <div class="grid max-w-2xl grid-cols-3 gap-6 sm:px-6 lg:max-w-5xl lg:grid-flow-col-dense lg:grid-cols-6">
        {{-- USER TASKS --}}
        <div class="space-y-6 col-span-3 lg:col-start-1 lg:col-span-3">
            <livewire:dashboard.user-tasks />
        </div>

        <div class="space-y-6 col-span-3 lg:col-start-4 lg:col-span-3">
            {{-- VENDOR DETAILS --}}
            <livewire:vendors.vendor-details :vendor="$user->vendor" :expanded="false" />
            {{-- VENDOR TEAM MEMBERS --}}
            <livewire:users.users-index :vendor="$user->vendor" :view="'vendors.show'"/>
        </div>

        {{-- GRAPH --}}
        @can('hasAdminRole', $user)
            <div class="space-y-6 col-span-3 lg:col-start-1 lg:col-span-6">
                <livewire:sheets.sheet-monthly />
            </div>
        @endcan
    </div>

    <livewire:tasks.task-create />
</div>

