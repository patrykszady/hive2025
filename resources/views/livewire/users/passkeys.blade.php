<div>
    <x-details.card
        title="Your Passkeys"
        subheading="Sign in faster and safer with biometrics or your device PIN."
        :canEdit="false"
    >
        <x-slot:details>
            @if($this->canRegister)
                <div class="space-y-4">
                    <x-passkey-benefits-callout />

                    <div id="passkey-error" class="hidden">
                        <flux:callout color="rose" icon="exclamation-triangle">
                            <flux:callout.heading id="passkey-error-heading"></flux:callout.heading>
                            <flux:callout.text id="passkey-error-text"></flux:callout.text>
                        </flux:callout>
                    </div>

                    <div id="passkey-success" class="hidden">
                        <flux:callout color="green" icon="check-circle">
                            <flux:callout.heading>Passkey added.</flux:callout.heading>
                            <flux:callout.text>You can now use it to sign in on this device.</flux:callout.text>
                        </flux:callout>
                    </div>

                    <div id="passkey-unsupported" class="hidden">
                        <flux:callout color="zinc" icon="information-circle">
                            <flux:callout.heading>Passkeys aren't supported on this browser.</flux:callout.heading>
                            <flux:callout.text>
                                Try a recent version of Chrome, Edge, Safari, or Firefox on a device with a screen lock.
                            </flux:callout.text>
                        </flux:callout>
                    </div>

                    <div>
                        <flux:button type="button" variant="primary" id="add-passkey-btn">
                            <span class="inline-flex items-center gap-2">
                                <flux:icon.finger-print class="w-5 h-5" />
                                <span id="add-passkey-text">Add a passkey</span>
                            </span>
                        </flux:button>
                    </div>
                </div>
            @endif

            <div class="@if($this->canRegister) mt-6 @endif space-y-3">
                @if($this->canRegister)
                    <flux:separator variant="subtle" />
                @endif

                <flux:heading size="sm">
                    @if(auth()->id() === $user->id)
                        Registered passkeys
                    @else
                        {{ $user->first_name ?? 'User' }}'s passkeys
                    @endif
                </flux:heading>

                @if($this->passkeys->isEmpty())
                    <div class="rounded-md border border-dashed border-zinc-200 dark:border-zinc-700 p-6 text-center">
                        <flux:icon.finger-print class="w-8 h-8 mx-auto text-zinc-400" />
                        <div class="mt-2 text-sm font-medium text-zinc-700 dark:text-zinc-200">No passkeys yet</div>
                        <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            @if($this->canRegister)
                                Add a passkey above to sign in without a password.
                            @else
                                This user hasn't set up any passkeys.
                            @endif
                        </div>
                    </div>
                @else
                    {{-- Four columns on purpose. This table had seven (Passkey,
                         Device, Created, Last updated, Origin, Status, Actions)
                         and scrolled horizontally inside its card. Device
                         duplicated Passkey outright — the alias FALLS BACK to
                         the device name, so unrenamed rows said "Windows" twice
                         — and the rest carried one short value each. Everything
                         secondary now lives as a sub-line under the column it
                         belongs to. --}}
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Passkey</flux:table.column>
                            <flux:table.column>Created</flux:table.column>
                            <flux:table.column>Status</flux:table.column>
                            @if($this->canManage)
                                {{-- Icon-only 3-dot menu per row; a header label
                                     over it just restates the obvious. --}}
                                <flux:table.column></flux:table.column>
                            @endif
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach($this->passkeys as $passkey)
                                @php
                                    $deviceType = $passkey->device_type ?: 'Unknown';
                                    $deviceName = $passkey->device_name ?: $deviceType;
                                    $alias = $passkey->alias ?: $deviceName ?: ('Passkey ' . substr($passkey->id, 0, 8));
                                    $transports = is_array($passkey->transports)
                                        ? implode(', ', $passkey->transports)
                                        : (string) ($passkey->transports ?? '');
                                    // The sub-line under the name: device type only when the
                                    // alias doesn't already say it (a renamed "My iPhone" keeps
                                    // its "iOS"; an unrenamed "Windows" doesn't repeat itself),
                                    // then the origin host — which site this passkey belongs to,
                                    // the fact that actually distinguishes rows — and transports.
                                    $originHost = $passkey->origin ? parse_url($passkey->origin, PHP_URL_HOST) : null;
                                    $meta = collect([
                                        strcasecmp($alias, $deviceType) !== 0 && $deviceType !== 'Unknown' ? $deviceType : null,
                                        $originHost,
                                        $transports !== '' ? $transports : null,
                                    ])->filter()->implode(' · ');
                                    $isRenaming = $this->renamingId === $passkey->id;
                                @endphp
                                <flux:table.row :key="$passkey->id">
                                    <flux:table.cell>
                                        @if($isRenaming)
                                            <form wire:submit.prevent="saveRename" class="flex items-center gap-2">
                                                <flux:input
                                                    wire:model="newAlias"
                                                    size="sm"
                                                    autofocus
                                                    maxlength="64"
                                                    placeholder="e.g. My iPhone"
                                                />
                                                <flux:button type="submit" size="sm" variant="primary">Save</flux:button>
                                                <flux:button type="button" size="sm" variant="ghost" wire:click="cancelRename">
                                                    Cancel
                                                </flux:button>
                                            </form>
                                            @error('newAlias')
                                                <div class="mt-1 text-xs text-red-600">{{ $message }}</div>
                                            @enderror
                                        @else
                                            {{-- title carries the credential id — it only ever
                                                 mattered for telling two same-named rows apart,
                                                 which the origin host now mostly does. --}}
                                            <div class="flex flex-col" title="{{ $passkey->id }}">
                                                <span class="font-medium">{{ $alias }}</span>
                                                @if($meta !== '')
                                                    <span class="text-xs text-zinc-500">{{ $meta }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </flux:table.cell>

                                    <flux:table.cell>
                                        <div class="flex flex-col">
                                            <span class="whitespace-nowrap">{{ $passkey->created_at?->format('M j, Y') ?? '—' }}</span>
                                            @if($passkey->updated_at)
                                                <span class="text-xs text-zinc-500 whitespace-nowrap">Edited {{ $passkey->updated_at->diffForHumans() }}</span>
                                            @endif
                                        </div>
                                    </flux:table.cell>

                                    <flux:table.cell>
                                        @if($passkey->disabled_at)
                                            <flux:badge color="zinc" size="sm">Disabled</flux:badge>
                                        @else
                                            <flux:badge color="green" size="sm">Active</flux:badge>
                                        @endif
                                    </flux:table.cell>

                                    @if($this->canManage)
                                        <flux:table.cell>
                                            @if(!$isRenaming)
                                                <flux:dropdown position="bottom" align="end">
                                                    <flux:button size="sm" variant="ghost" square icon="ellipsis-vertical" aria-label="Passkey actions" />
                                                    <flux:menu>
                                                        <flux:menu.item
                                                            icon="pencil-square"
                                                            wire:click="startRename('{{ $passkey->id }}')"
                                                        >
                                                            Rename
                                                        </flux:menu.item>

                                                        @if($passkey->disabled_at)
                                                            <flux:menu.item
                                                                icon="check-circle"
                                                                wire:click="enablePasskey('{{ $passkey->id }}')"
                                                            >
                                                                Re-enable
                                                            </flux:menu.item>
                                                        @else
                                                            <flux:menu.item
                                                                icon="no-symbol"
                                                                wire:click="disablePasskey('{{ $passkey->id }}')"
                                                                wire:confirm="Disable this passkey? You won't be able to sign in with it until it's re-enabled."
                                                            >
                                                                Disable
                                                            </flux:menu.item>
                                                        @endif

                                                        <flux:menu.separator />

                                                        <flux:menu.item
                                                            icon="trash"
                                                            variant="danger"
                                                            wire:click="deletePasskey('{{ $passkey->id }}')"
                                                            wire:confirm.prompt="Permanently delete this passkey? This cannot be undone.\n\nType DELETE to confirm|DELETE"
                                                        >
                                                            Delete
                                                        </flux:menu.item>
                                                    </flux:menu>
                                                </flux:dropdown>
                                            @endif
                                        </flux:table.cell>
                                    @endif
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif

                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                    Tip: a passkey is stored on a single device or password manager. To sign in from a new device, add a passkey there too — or use one synced via iCloud Keychain, Google Password Manager, or 1Password.
                </div>
            </div>
        </x-slot:details>
    </x-details.card>

    @if($this->canRegister)
        <x-passkey-registration
            button-id="add-passkey-btn"
            button-text-id="add-passkey-text"
            complete-method="onRegistered"
        />

        <script>
            (function () {
                const checkSupport = () => {
                    const unsupported = document.getElementById('passkey-unsupported');
                    const addBtn = document.getElementById('add-passkey-btn');
                    if (!unsupported || !addBtn) return;

                    const supported = typeof window !== 'undefined'
                        && window.PublicKeyCredential
                        && (!window.Webpass || !Webpass.isUnsupported());

                    if (!supported) {
                        unsupported.classList.remove('hidden');
                        addBtn.setAttribute('disabled', 'disabled');
                        addBtn.classList.add('opacity-50', 'cursor-not-allowed');
                    }
                };

                document.addEventListener('DOMContentLoaded', checkSupport);
                document.addEventListener('livewire:navigated', checkSupport);
                // Also re-check when Webpass finishes loading
                window.addEventListener('load', checkSupport);
            })();
        </script>
    @endif
</div>
