<div class="space-y-6">
    <x-island-card heading="Options" subheading="Configure your company settings" :separator="true">

        <form wire:submit="save" class="space-y-6">
            {{-- Short Name --}}
            <flux:field>
                <flux:label>Short Business Name</flux:label>
                <flux:description>A shorter version of your business name (e.g., "GS Construction" instead of "GS Construction & Remodeling")</flux:description>
                <flux:input wire:model="short_name" placeholder="{{ $vendor->business_name }}" />
                <flux:error name="short_name" />
            </flux:field>

            {{-- SMS Notifications --}}
            <flux:field>
                <flux:label>SMS Notifications</flux:label>
                <flux:description>Choose which task SMS types are enabled for your company.</flux:description>

                <div class="mt-2 flex flex-col gap-4">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Team SMS</div>
                            <div class="text-xs text-zinc-500">Daily reminders and schedule changes for your team.</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <flux:switch wire:model.live="sms_team_enabled" align="left" />
                            <span class="text-sm text-zinc-600">{{ $sms_team_enabled ? 'Enabled' : 'Disabled' }}</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Client SMS</div>
                            <div class="text-xs text-zinc-500">Schedule changes and updates sent to clients.</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <flux:switch wire:model.live="sms_client_enabled" align="left" />
                            <span class="text-sm text-zinc-600">{{ $sms_client_enabled ? 'Enabled' : 'Disabled' }}</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Vendor SMS</div>
                            <div class="text-xs text-zinc-500">Availability and schedule notifications to subcontractors.</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <flux:switch wire:model.live="sms_vendor_enabled" align="left" />
                            <span class="text-sm text-zinc-600">{{ $sms_vendor_enabled ? 'Enabled' : 'Disabled' }}</span>
                        </div>
                    </div>
                </div>

                <div class="mt-2 space-y-1">
                    <flux:error name="sms_team_enabled" />
                    <flux:error name="sms_client_enabled" />
                    <flux:error name="sms_vendor_enabled" />
                </div>
            </flux:field>

            {{-- Business Logo --}}
            <div class="space-y-3">
                <flux:file-upload wire:model="logo" label="Business Logo" :error="$errors->first('logo')">
                    <flux:file-upload.dropzone
                        heading="Drop file or click to browse"
                        text="JPG, PNG, GIF up to 10MB"
                        with-progress
                        inline
                    />
                </flux:file-upload>

                <div class="mt-3 flex flex-col gap-2">
                    @if ($logo)
                        <flux:file-item
                            :heading="$logo->getClientOriginalName()"
                            :image="$logo->temporaryUrl()"
                            :size="$logo->getSize()"
                        >
                            <x-slot name="actions">
                                <flux:file-item.remove
                                    wire:click="removePendingLogo"
                                    aria-label="{{ 'Remove file: ' . $logo->getClientOriginalName() }}"
                                />
                            </x-slot>
                        </flux:file-item>
                    @elseif ($existing_logo)
                        <flux:file-item
                            :heading="basename($existing_logo)"
                            :image="Storage::url($existing_logo)"
                            :size="Storage::disk('public')->exists($existing_logo) ? Storage::disk('public')->size($existing_logo) : 0"
                        >
                            <x-slot name="actions">
                                <flux:file-item.remove
                                    wire:click="removeLogo"
                                    aria-label="{{ 'Remove file: ' . basename($existing_logo) }}"
                                />
                            </x-slot>
                        </flux:file-item>
                    @endif
                </div>
            </div>

            {{-- Business Hours (system-wide) --}}
            <div class="space-y-2">
                <div class="text-sm font-semibold text-zinc-800 dark:text-white">Business Hours</div>
                <flux:description>
                    Sets your company's working window. Inbound calls outside these hours skip the welcome message and admin ring, going straight to the voicemail menu. Also seeds the default Notification Window for each team member (which they may override on their own profile).
                </flux:description>

                <div class="mt-2 flex gap-3">
                    <div class="flex flex-col gap-1 flex-1">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Open</div>
                        <flux:input wire:model="business_hours_start" type="time" />
                        <flux:error name="business_hours_start" />
                    </div>
                    <div class="flex flex-col gap-1 flex-1">
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Close</div>
                        <flux:input wire:model="business_hours_end" type="time" />
                        <flux:error name="business_hours_end" />
                    </div>
                    <div class="flex flex-col gap-1 flex-1">
                        <div class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Timezone</div>
                        <flux:input :value="$vendor->timezone ?: 'America/Chicago'" disabled />
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">Set by your business address.</div>
                    </div>
                </div>
                <div class="mt-1 space-y-1">
                    <flux:error name="business_hours_start" />
                    <flux:error name="business_hours_end" />
                </div>

                <div class="mt-3 flex flex-wrap gap-3">
                    @foreach (['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6, 'Sun' => 7] as $label => $iso)
                        <label class="flex items-center gap-2 cursor-pointer">
                            <flux:checkbox wire:model="business_hours_days" value="{{ $iso }}" />
                            <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <flux:error name="business_hours_days" />
            </div>

            {{-- Contract Signing --}}
            <flux:field>
                <flux:label>Default Contract Signers</flux:label>
                <flux:description>Select which admin users must sign contracts by default. This can be overridden per estimate.</flux:description>

                <div class="mt-2">
                    @if ($adminUsersWithPhones->isNotEmpty())
                        <div class="flex flex-col gap-2">
                            @foreach ($adminUsersWithPhones as $adminUser)
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <flux:checkbox wire:model="default_contract_signers" value="{{ $adminUser->id }}" />
                                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $adminUser->full_name }}</span>
                                </label>
                            @endforeach
                        </div>
                    @else
                        <div class="text-xs text-zinc-400 italic">No admin users with cell phones found.</div>
                    @endif
                </div>
            </flux:field>

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">Save Options</span>
                    <span wire:loading wire:target="save">Saving...</span>
                </flux:button>
            </div>
        </form>
    </x-island-card>
</div>
