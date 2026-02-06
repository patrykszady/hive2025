<div class="space-y-6">
    <x-island-card heading="Options" subheading="Configure your company settings" :separator="true">

        <form wire:submit="save" class="space-y-6">
            {{-- Timezone --}}
            <flux:field>
                <flux:label>Timezone</flux:label>
                <flux:description>Used for PDF generation and server-side date formatting</flux:description>
                <flux:select wire:model="timezone" placeholder="Select timezone...">
                    <flux:select.option value="">Use system default</flux:select.option>
                    <flux:select.option value="America/New_York">Eastern Time (America/New_York)</flux:select.option>
                    <flux:select.option value="America/Chicago">Central Time (America/Chicago)</flux:select.option>
                    <flux:select.option value="America/Denver">Mountain Time (America/Denver)</flux:select.option>
                    <flux:select.option value="America/Phoenix">Arizona (America/Phoenix)</flux:select.option>
                    <flux:select.option value="America/Los_Angeles">Pacific Time (America/Los_Angeles)</flux:select.option>
                    <flux:select.option value="America/Anchorage">Alaska (America/Anchorage)</flux:select.option>
                    <flux:select.option value="Pacific/Honolulu">Hawaii (Pacific/Honolulu)</flux:select.option>
                </flux:select>
                <flux:error name="timezone" />
            </flux:field>

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

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">Save Options</span>
                    <span wire:loading wire:target="save">Saving...</span>
                </flux:button>
            </div>
        </form>
    </x-island-card>
</div>
