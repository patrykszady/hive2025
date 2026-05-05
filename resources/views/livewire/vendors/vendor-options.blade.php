<div
    class="space-y-6"
    x-data="{
        audio: null,
        playTts(data) {
            if (this.audio) { this.audio.pause(); this.audio = null; }
            this.audio = new Audio('data:audio/mpeg;base64,' + data);
            this.audio.play();
        }
    }"
    @play-tts-preview.window="playTts($event.detail.audioData)"
>
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

            {{-- Phone System --}}
            @if($vendor->id === 1)
            <flux:field>
                <flux:label>Phone System</flux:label>
                <flux:description>Configure inbound call routing, welcome messages, and voicemail.</flux:description>

                <div class="mt-2 flex flex-col gap-4">
                    {{-- Call Recipients --}}
                    <div>
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100 mb-2">Call Recipients</div>
                        <div class="text-xs text-zinc-500 mb-3">Select which team members receive inbound calls.</div>
                        @if ($adminUsersWithPhones->isNotEmpty())
                            <div class="flex flex-col gap-2">
                                @foreach ($adminUsersWithPhones as $adminUser)
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <flux:checkbox wire:model="call_recipients" value="{{ $adminUser->id }}" />
                                        <div>
                                            <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $adminUser->full_name }}</span>
                                            <span class="text-xs text-zinc-500 ml-1">{{ $adminUser->cell_phone }}</span>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        @else
                            <div class="text-xs text-zinc-400 italic">No admin users with cell phones found.</div>
                        @endif
                    </div>

                    {{-- Welcome Message --}}
                    <div class="space-y-2">
                        <div>
                            <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Welcome Message</div>
                            <div class="text-xs text-zinc-500">Played to the caller while we ring your team.</div>
                        </div>
                        <div class="space-y-4 pl-1 border-l-2 border-zinc-200 dark:border-zinc-700 ml-1">
                            {{-- Known Caller --}}
                            <div class="pl-3">
                                <div class="flex items-center justify-between gap-2 mb-1">
                                    <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                        <flux:badge size="sm" color="sky" class="mr-1">
                                            <flux:icon.user variant="micro" class="size-3" />
                                        </flux:badge>
                                        Welcome <span class="text-xs font-normal text-zinc-400">(known caller)</span>
                                    </div>
                                    <flux:button size="xs" variant="ghost" icon="play" wire:click="previewTts('welcome')" wire:loading.attr="disabled" wire:target="previewTts" title="Preview known caller welcome" />
                                </div>
                                <flux:textarea wire:model="welcome_message" rows="2" placeholder="{{ \App\Livewire\Vendors\VendorOptions::DEFAULT_WELCOME }}" resize="vertical" />
                                <div class="text-xs text-zinc-400 mt-1">Placeholders: <code class="text-zinc-500">{name}</code> <code class="text-zinc-500">{company}</code> <code class="text-zinc-500">{greeting}</code></div>
                            </div>

                            {{-- Unknown Caller --}}
                            <div class="pl-3">
                                <div class="flex items-center justify-between gap-2 mb-1">
                                    <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                        <flux:badge size="sm" color="zinc" class="mr-1">
                                            <flux:icon.user variant="micro" class="size-3" />
                                        </flux:badge>
                                        Welcome <span class="text-xs font-normal text-zinc-400">(unknown caller)</span>
                                    </div>
                                    <flux:button size="xs" variant="ghost" icon="play" wire:click="previewTts('welcome_unknown')" wire:loading.attr="disabled" wire:target="previewTts" title="Preview unknown caller welcome" />
                                </div>
                                <flux:textarea wire:model="welcome_message_unknown" rows="2" placeholder="{{ \App\Livewire\Vendors\VendorOptions::DEFAULT_WELCOME_UNKNOWN }}" resize="vertical" />
                                <div class="text-xs text-zinc-400 mt-1">No <code class="text-zinc-500">{name}</code> available. Placeholders: <code class="text-zinc-500">{company}</code> <code class="text-zinc-500">{greeting}</code></div>
                            </div>
                        </div>
                    </div>

                    {{-- Screening Prompt (played to the answering recipient) --}}
                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Screening Prompt</div>
                                <div class="text-xs text-zinc-500">Played to your team member when they answer. They can hang up to send the caller to voicemail or stay on the line to connect.</div>
                            </div>
                            <flux:button size="xs" variant="ghost" icon="play" wire:click="previewTts('screening')" wire:loading.attr="disabled" wire:target="previewTts" title="Preview screening prompt" />
                        </div>
                        <div>
                            <flux:textarea wire:model="screening_message" rows="2" placeholder="{{ \App\Livewire\Vendors\VendorOptions::DEFAULT_SCREENING }}" resize="vertical" />
                            <div class="text-xs text-zinc-400 mt-1">Placeholders: <code class="text-zinc-500">{name}</code> (caller) <code class="text-zinc-500">{company}</code> <code class="text-zinc-500">{greeting}</code></div>
                        </div>
                    </div>

                    {{-- Voicemail Menu --}}
                    <div class="space-y-2">
                        <div>
                            <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Voicemail Menu</div>
                            <div class="text-xs text-zinc-500">If no one answers, play an interactive menu: re-dial, send a text, or leave a voicemail.</div>
                        </div>
                        <div class="space-y-4 pl-1 border-l-2 border-zinc-200 dark:border-zinc-700 ml-1">
                                {{-- IVR Main Menu: Known Caller --}}
                                <div class="pl-3">
                                    <div class="flex items-center justify-between gap-2 mb-1">
                                        <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                            <flux:badge size="sm" color="sky" class="mr-1">
                                                <flux:icon.user variant="micro" class="size-3" />
                                            </flux:badge>
                                            Menu Prompt <span class="text-xs font-normal text-zinc-400">(known caller)</span>
                                        </div>
                                        <flux:button size="xs" variant="ghost" icon="play" wire:click="previewTts('voicemail')" wire:loading.attr="disabled" wire:target="previewTts" title="Preview known caller menu prompt" />
                                    </div>
                                    <flux:textarea wire:model="voicemail_message" rows="2" placeholder="{{ \App\Livewire\Vendors\VendorOptions::DEFAULT_VOICEMAIL }}" resize="vertical" />
                                    <div class="text-xs text-zinc-400 mt-1">Includes Press 1 re-dial option. Placeholders: <code class="text-zinc-500">{name}</code> <code class="text-zinc-500">{company}</code> <code class="text-zinc-500">{greeting}</code></div>
                                </div>

                                {{-- IVR Main Menu: Unknown Caller --}}
                                <div class="pl-3">
                                    <div class="flex items-center justify-between gap-2 mb-1">
                                        <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                            <flux:badge size="sm" color="zinc" class="mr-1">
                                                <flux:icon.user variant="micro" class="size-3" />
                                            </flux:badge>
                                            Menu Prompt <span class="text-xs font-normal text-zinc-400">(unknown caller)</span>
                                        </div>
                                        <flux:button size="xs" variant="ghost" icon="play" wire:click="previewTts('voicemail_unknown')" wire:loading.attr="disabled" wire:target="previewTts" title="Preview unknown caller menu prompt" />
                                    </div>
                                    <flux:textarea wire:model="voicemail_message_unknown" rows="2" placeholder="{{ \App\Livewire\Vendors\VendorOptions::DEFAULT_VOICEMAIL_UNKNOWN }}" resize="vertical" />
                                    <div class="text-xs text-zinc-400 mt-1">No re-dial option. Placeholders: <code class="text-zinc-500">{name}</code> <code class="text-zinc-500">{company}</code> <code class="text-zinc-500">{greeting}</code></div>
                                </div>

                                {{-- Press 1: Re-dial --}}
                                <div class="pl-3">
                                    <div class="flex items-center justify-between gap-2 mb-1">
                                        <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                            <flux:badge size="sm" color="blue" class="mr-1">1</flux:badge> Re-dial Response
                                        </div>
                                        <flux:button size="xs" variant="ghost" icon="play" wire:click="previewTts('ivr_press1')" wire:loading.attr="disabled" wire:target="previewTts" title="Preview press 1 response" />
                                    </div>
                                    <flux:textarea wire:model="ivr_press1_message" rows="1" placeholder="{{ \App\Livewire\Vendors\VendorOptions::DEFAULT_IVR_PRESS1 }}" resize="vertical" />
                                    <div class="text-xs text-zinc-400 mt-1">Played before re-dialing your team. Placeholders: <code class="text-zinc-500">{name}</code> <code class="text-zinc-500">{company}</code> <code class="text-zinc-500">{greeting}</code></div>
                                </div>

                                {{-- Press 2: Send text --}}
                                <div class="pl-3">
                                    <div class="flex items-center justify-between gap-2 mb-1">
                                        <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                            <flux:badge size="sm" color="green" class="mr-1">2</flux:badge> Send Text Response
                                        </div>
                                        <flux:button size="xs" variant="ghost" icon="play" wire:click="previewTts('ivr_press2')" wire:loading.attr="disabled" wire:target="previewTts" title="Preview press 2 response" />
                                    </div>
                                    <flux:textarea wire:model="ivr_press2_message" rows="1" placeholder="{{ \App\Livewire\Vendors\VendorOptions::DEFAULT_IVR_PRESS2 }}" resize="vertical" />
                                    <div class="text-xs text-zinc-400 mt-1">Played after sending SMS to your team. Placeholders: <code class="text-zinc-500">{name}</code> <code class="text-zinc-500">{company}</code> <code class="text-zinc-500">{greeting}</code></div>
                                </div>

                                {{-- Stay on line: Voicemail Greeting --}}
                                <div class="pl-3">
                                    <div class="flex items-center justify-between gap-2 mb-1">
                                        <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                            <flux:badge size="sm" color="zinc" class="mr-1">&hellip;</flux:badge> Voicemail Greeting
                                        </div>
                                        <flux:button size="xs" variant="ghost" icon="play" wire:click="previewTts('voicemail_greeting')" wire:loading.attr="disabled" wire:target="previewTts" title="Preview voicemail greeting" />
                                    </div>
                                    <flux:textarea wire:model="voicemail_greeting" rows="2" placeholder="{{ \App\Livewire\Vendors\VendorOptions::DEFAULT_VOICEMAIL_GREETING }}" resize="vertical" />
                                    <div class="text-xs text-zinc-400 mt-1">Played before the beep when caller stays on line. Placeholders: <code class="text-zinc-500">{name}</code> <code class="text-zinc-500">{company}</code> <code class="text-zinc-500">{greeting}</code></div>
                                </div>
                            </div>
                    </div>
                </div>
            </flux:field>
            @endif

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
