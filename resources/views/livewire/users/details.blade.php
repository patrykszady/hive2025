<x-details.card
    title="User Details"
    subheading="User and related details."
    :canEdit="auth()->user()->can('update', $user)"
    wire:init="$refresh"
>
    <x-slot:header_buttons>
        @can('update', $user->vendor)
            <flux:button.group>
                <flux:button
                    wire:click="$dispatchTo('users.user-create', 'editMember', { user: {{$user->id}} })"
                    size="sm"
                >
                    Edit User
                </flux:button>
                <flux:dropdown position="bottom" align="end">
                    <flux:button icon-trailing="chevron-down" size="sm"></flux:button>

                    <flux:menu>
                        <flux:menu.item
                            wire:click="$dispatchTo('users.user-create', 'removeMember', { user: {{$user->id}} })"
                            wire:confirm.prompt="Are you sure you want to remove this User from this Vendor?\n\nType REMOVE to confirm|REMOVE"
                            size="sm"
                            variant="danger"
                        >
                            Remove User from Vendor
                        </flux:menu.item>
                    </flux:menu>
                </flux:dropdown>   
            </flux:button.group>
        @else
            <flux:button
                wire:click="$dispatchTo('users.user-create', 'editMember', { user: {{$user->id}} })"
                size="sm"
            >
                Edit User
            </flux:button>
        @endcan
    </x-slot:header_buttons>

    <x-slot:details>
    {{-- Lightweight skeleton guard to avoid flash before hydration --}}
    @php($hydrated = isset($user) && $user->id)
    <x-details.row title="Name" :content="$hydrated ? $user->full_name : 'Loading...'" />
    <x-details.row title="Email" :content="$hydrated ? $user->email : 'Loading...'" copyable />
    <x-details.row title="Cell Phone" :content="$hydrated && $user->cell_phone ? preg_replace('/^(\d{3})(\d{3})(\d{4})$/', '($1) $2-$3', $user->cell_phone) : 'Loading...'" copyable />

        @if($user->isEmployed())
            <x-details.row title="" :content="auth()->user()->vendor->name . ' Details:'" />
            @can('update', $user)
                <x-details.row 
                    title="Start Date" 
                    :content="$user->vendor_pivot->start_date->format('m/d/Y')" 
                />

                <x-details.row 
                    title="Hourly Rate" 
                    :content="money($user->vendor_pivot->hourly_rate)" 
                />
                {{-- @can('create_team_member', [App\Models\User::class, auth()->user()->vendor->id])
                    <x-details.row 
                        title="Hourly Rate" 
                        :content="money($user->vendor_pivot->hourly_rate)" 
                    />
                @endcan --}}
            @endcan

            <x-details.row title="Vendor Role" :content="$user->getRoleForVendor(auth()->user()->vendor->id)" />

            @if($user->via_vendor)
                <x-details.row title="Via Vendor" :content="$user->via_vendor->business_name . ' (' . $user->via_vendor->business_type . ')'" href="{{ route('vendors.show', $user->via_vendor->id) }}" />
            @endif
        @endif

        @if(auth()->id() === $user->id)
            <div class="mt-4 space-y-3">
                <flux:separator variant="subtle" />
                <div class="text-sm text-zinc-500">Passkeys let you sign in without a password.</div>
                <flux:button type="button" variant="outline" id="passkey-register">
                    Register Passkey
                </flux:button>
                <div id="passkey-register-error" class="hidden text-sm text-red-600"></div>
                <div id="passkey-register-success" class="hidden text-sm text-green-600">Passkey registered.</div>
            </div>

            <div class="mt-6 space-y-3">
                <flux:separator variant="subtle" />
                <div class="text-sm text-zinc-500">Your passkeys</div>

                @if($this->passkeys->isEmpty())
                    <div class="text-sm text-zinc-400">No passkeys registered yet.</div>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Passkey</flux:table.column>
                            <flux:table.column>Type</flux:table.column>
                            <flux:table.column>Created</flux:table.column>
                            <flux:table.column>Origin</flux:table.column>
                            <flux:table.column>Status</flux:table.column>
                            <flux:table.column>Transports</flux:table.column>
                            <flux:table.column>Actions</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach($this->passkeys as $passkey)
                                @php($label = $passkey->device_name ?: ($passkey->alias ?: ('Passkey ' . substr($passkey->id, 0, 8))))
                                @php($deviceType = $passkey->device_type ?: 'Unknown')
                                @php($transports = is_array($passkey->transports) ? implode(', ', $passkey->transports) : ($passkey->transports ?: ''))
                                <flux:table.row :key="$passkey->id">
                                    <flux:table.cell>{{ $label }}</flux:table.cell>
                                    <flux:table.cell>{{ $deviceType }}</flux:table.cell>
                                    <flux:table.cell>{{ $passkey->created_at?->format('M j, Y') ?? '—' }}</flux:table.cell>
                                    <flux:table.cell class="whitespace-normal break-words">{{ $passkey->origin ?? '—' }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if($passkey->disabled_at)
                                            <flux:badge color="zinc" size="sm">Disabled</flux:badge>
                                        @else
                                            <flux:badge color="green" size="sm">Active</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="whitespace-normal break-words">{{ $transports !== '' ? $transports : '—' }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:button
                                            size="sm"
                                            variant="danger"
                                            :disabled="$passkey->disabled_at"
                                            wire:click="revokePasskey('{{ $passkey->id }}')"
                                            wire:confirm.prompt="Revoke this passkey? Type REVOKE to confirm|REVOKE"
                                        >
                                            Revoke
                                        </flux:button>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </div>
        @endif
    </x-slot:details>
</x-details.card>

@if(auth()->id() === $user->id)
    <script src="https://cdn.jsdelivr.net/npm/@laragear/webpass@2/dist/webpass.js" defer></script>
    <script>
        (function() {
            const initPasskeyRegister = () => {
                const button = document.getElementById('passkey-register');
                const errorElement = document.getElementById('passkey-register-error');
                const successElement = document.getElementById('passkey-register-success');

                if (!button || button.dataset.passkeyBound === 'true') {
                    return;
                }

                button.dataset.passkeyBound = 'true';

                const showError = (message) => {
                    if (!errorElement) return;
                    errorElement.textContent = message;
                    errorElement.classList.remove('hidden');
                    if (successElement) successElement.classList.add('hidden');
                };

                const showSuccess = () => {
                    if (errorElement) errorElement.classList.add('hidden');
                    if (successElement) successElement.classList.remove('hidden');
                };

                if (window.Webpass && Webpass.isUnsupported()) {
                    button.setAttribute('disabled', 'disabled');
                    showError("Passkeys aren't supported on this device.");
                    return;
                }

                button.addEventListener('click', async () => {
                    if (errorElement) errorElement.classList.add('hidden');
                    if (successElement) successElement.classList.add('hidden');

                    if (!window.Webpass) {
                        showError('Passkeys are still loading. Try again in a moment.');
                        return;
                    }

                    try {
                        const { success, error } = await Webpass.attest(
                            { path: '/webauthn/register/options', credentials: 'include' },
                            { path: '/webauthn/register', credentials: 'include' }
                        );

                        if (success) {
                            showSuccess();
                            setTimeout(() => window.location.reload(), 1000);
                            return;
                        }

                        if (error?.name === 'NotAllowedError' || error?.name === 'AttestationCancelled') {
                            showError('Passkey setup was cancelled or not allowed. If a passkey already exists for this device, remove it from your device settings first.');
                        } else if (error?.name === 'InvalidStateError') {
                            showError('A passkey already exists for this account on this device.');
                        } else {
                            showError(error?.message || 'Passkey registration failed.');
                        }
                    } catch (exception) {
                        if (exception?.name === 'InvalidStateError') {
                            showError('A passkey already exists for this account on this device.');
                        } else {
                            showError(exception?.message || 'Passkey registration failed.');
                        }
                    }
                });
            };

            document.addEventListener('DOMContentLoaded', initPasskeyRegister);
            document.addEventListener('livewire:navigated', initPasskeyRegister);
        })();
    </script>
@endif