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
    <x-island-card heading="Phone System" subheading="Who inbound calls ring, and what callers and your team hear" :separator="true">

        <form wire:submit="save" class="space-y-6">
            <flux:field>
                <flux:description>
                    Calls ring through during business hours, set on <flux:link href="{{ route('vendor_options.index') }}" wire:navigate>Options</flux:link>; after hours only callers saved in Hive ring through.
                </flux:description>

                <div class="mt-2 flex flex-col gap-4">
                    {{-- Call Recipients (list order = ring order) --}}
                    <div>
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100 mb-2">Call Recipients</div>
                        <div class="text-xs text-zinc-500 mb-3">Inbound calls ring the first person below; each next person rings after {{ \App\Http\Controllers\Api\TelnyxWebhookController::RING_SECONDS }} seconds without an answer. Use the arrows to change who is called first. Whoever is on the call can press 9 to ring the others in, and anyone selected here who calls this line during a live call joins it. Changes apply when you save.</div>
                        @php
                            $callOrder = $this->orderedRecipientIds();
                        @endphp
                        @if ($adminUsersWithPhones->isNotEmpty())
                            <div class="flex flex-col gap-2">
                                @foreach ($this->recipientRows() as $adminUser)
                                    @php
                                        $position = array_search((int) $adminUser->id, $callOrder, true);
                                    @endphp
                                    <div wire:key="call-recipient-{{ $adminUser->id }}" class="flex items-center gap-3">
                                        <span class="w-5 text-right text-xs font-medium tabular-nums text-zinc-500">{{ $position === false ? '' : $position + 1 }}</span>
                                        <label class="flex flex-1 items-center gap-3 cursor-pointer min-w-0">
                                            <flux:checkbox wire:model.live="call_recipients" value="{{ $adminUser->id }}" />
                                            <div class="min-w-0 truncate">
                                                <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $adminUser->full_name }}</span>
                                                <span class="text-xs text-zinc-500 ml-1">{{ $adminUser->cell_phone }}</span>
                                            </div>
                                        </label>
                                        @if ($position !== false && count($callOrder) > 1)
                                            <div class="flex items-center gap-1">
                                                <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveRecipientUp({{ $adminUser->id }})" :disabled="$position === 0" title="Call earlier" aria-label="Call {{ $adminUser->full_name }} earlier" />
                                                <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveRecipientDown({{ $adminUser->id }})" :disabled="$position === count($callOrder) - 1" title="Call later" aria-label="Call {{ $adminUser->full_name }} later" />
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-xs text-zinc-400 italic">No admin users with cell phones found.</div>
                        @endif
                    </div>

                    {{-- Vendors and subs: their own ring order --}}
                    @if ($adminUsersWithPhones->isNotEmpty())
                        <div>
                            <flux:switch wire:model.live="vendor_call_order_enabled" align="left" label="Different order when a vendor or sub calls" description="A caller whose number is a vendor's business phone, or belongs to someone on a vendor's team, rings this order instead." />

                            @if ($vendor_call_order_enabled)
                                @php
                                    $vendorCallOrder = $this->orderedVendorRecipientIds();
                                @endphp
                                <flux:radio.group wire:model.live="vendor_call_ring_mode" variant="segmented" size="sm" class="mt-3 max-w-sm">
                                    <flux:radio value="one_by_one" label="One after another" />
                                    <flux:radio value="all" label="Everyone at once" />
                                </flux:radio.group>
                                <div class="text-xs text-zinc-500 mt-1">
                                    @if ($vendor_call_ring_mode === 'all')
                                        Everyone ticked rings together for {{ \App\Http\Controllers\Api\TelnyxWebhookController::RING_SECONDS }} seconds; the first to answer gets the caller, and the others can still answer and join.
                                    @else
                                        The first person rings; each next person rings after {{ \App\Http\Controllers\Api\TelnyxWebhookController::RING_SECONDS }} seconds without an answer.
                                    @endif
                                </div>
                                <div class="mt-3 flex flex-col gap-2">
                                    @foreach ($this->vendorRecipientRows() as $adminUser)
                                        @php
                                            $position = array_search((int) $adminUser->id, $vendorCallOrder, true);
                                        @endphp
                                        <div wire:key="vendor-call-recipient-{{ $adminUser->id }}" class="flex items-center gap-3">
                                            <span class="w-5 text-right text-xs font-medium tabular-nums text-zinc-500">{{ $position === false || $vendor_call_ring_mode === 'all' ? '' : $position + 1 }}</span>
                                            <label class="flex flex-1 items-center gap-3 cursor-pointer min-w-0">
                                                <flux:checkbox wire:model.live="vendor_call_recipients" value="{{ $adminUser->id }}" />
                                                <div class="min-w-0 truncate">
                                                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $adminUser->full_name }}</span>
                                                    <span class="text-xs text-zinc-500 ml-1">{{ $adminUser->cell_phone }}</span>
                                                </div>
                                            </label>
                                            @if ($position !== false && count($vendorCallOrder) > 1 && $vendor_call_ring_mode !== 'all')
                                                <div class="flex items-center gap-1">
                                                    <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveVendorRecipientUp({{ $adminUser->id }})" :disabled="$position === 0" title="Call earlier" aria-label="Call {{ $adminUser->full_name }} earlier when a vendor calls" />
                                                    <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveVendorRecipientDown({{ $adminUser->id }})" :disabled="$position === count($vendorCallOrder) - 1" title="Call later" aria-label="Call {{ $adminUser->full_name }} later when a vendor calls" />
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

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
                                <flux:textarea wire:model="welcome_message" rows="2" placeholder="{{ \App\Livewire\Vendors\PhoneSystemOptions::DEFAULT_WELCOME }}" resize="vertical" />
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
                                <flux:textarea wire:model="welcome_message_unknown" rows="2" placeholder="{{ \App\Livewire\Vendors\PhoneSystemOptions::DEFAULT_WELCOME_UNKNOWN }}" resize="vertical" />
                                <div class="text-xs text-zinc-400 mt-1">No <code class="text-zinc-500">{name}</code> available. Placeholders: <code class="text-zinc-500">{company}</code> <code class="text-zinc-500">{greeting}</code></div>
                            </div>
                        </div>
                    </div>

                    {{-- Recording Disclosure (prepended to every welcome message) --}}
                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                    Recording Disclosure
                                    <flux:badge size="sm" color="amber" class="ml-1">Required</flux:badge>
                                </div>
                                <div class="text-xs text-zinc-500">Spoken at the start of every recorded call before the welcome message. Required for two-party-consent jurisdictions (e.g. Illinois). Keep it short and unambiguous.</div>
                            </div>
                            <flux:button size="xs" variant="ghost" icon="play" wire:click="previewTts('recording_disclosure')" wire:loading.attr="disabled" wire:target="previewTts" title="Preview recording disclosure" />
                        </div>
                        <div>
                            <flux:textarea wire:model="recording_disclosure_message" rows="2" placeholder="{{ \App\Livewire\Vendors\PhoneSystemOptions::DEFAULT_RECORDING_DISCLOSURE }}" resize="vertical" />
                            <div class="text-xs text-zinc-400 mt-1">No placeholders. Captured inside the recording as audit trail of consent.</div>
                        </div>
                    </div>

                    {{-- Screening Prompt (played to the answering recipient) --}}
                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Screening Prompt</div>
                                <div class="text-xs text-zinc-500">Played to your team member when they answer, followed by "{{ \App\Livewire\Vendors\PhoneSystemOptions::SCREENING_INSTRUCTION }}" They are connected as soon as it ends and their phone is heard to be a person. A phone whose voicemail answers is recognised and dropped, and the next person rings. Off, whoever answers is connected at once, including a voicemail box.</div>
                            </div>
                            <flux:button size="xs" variant="ghost" icon="play" wire:click="previewTts('screening')" wire:loading.attr="disabled" wire:target="previewTts" title="Preview screening prompt" />
                        </div>
                        <flux:switch wire:model="screening_enabled" label="Announce the caller before connecting" />
                        <div x-show="$wire.screening_enabled" x-cloak>
                            <flux:textarea wire:model="screening_message" rows="2" placeholder="{{ \App\Livewire\Vendors\PhoneSystemOptions::DEFAULT_SCREENING }}" resize="vertical" />
                            <div class="text-xs text-zinc-400 mt-1">Placeholders: <code class="text-zinc-500">{name}</code> (caller) <code class="text-zinc-500">{company}</code> <code class="text-zinc-500">{greeting}</code></div>
                        </div>
                    </div>

                    {{-- Text sent to an inbound caller when the announced admin presses 1 --}}
                    <div class="space-y-2">
                        <div>
                            <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Text after pressing 1 on an incoming call</div>
                            <div class="text-xs text-zinc-500">While you hear who is calling, press 1 if you cannot take it: the caller gets this text, hears the same by voice, and every phone stops ringing. Logged as a promised callback, not voicemail.</div>
                        </div>
                        <div>
                            <flux:textarea wire:model="inbound_callback_text" rows="2" placeholder="{{ \App\Livewire\Vendors\PhoneSystemOptions::DEFAULT_INBOUND_CALLBACK_TEXT }}" resize="vertical" />
                            <div class="text-xs text-zinc-400 mt-1">Placeholder: <code class="text-zinc-500">{company}</code></div>
                        </div>
                    </div>

                    {{-- Text sent to an outbound target when the caller presses 1 --}}
                    <div class="space-y-2">
                        <div>
                            <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Text after pressing 1 on an outbound call</div>
                            <div class="text-xs text-zinc-500">On a call you placed from Hive, press 1 on your phone (for example when their voicemail picks up) and this text goes to the person you called. It appears in their Messages thread.</div>
                        </div>
                        <div>
                            <flux:textarea wire:model="missed_call_text" rows="2" placeholder="{{ \App\Livewire\Vendors\PhoneSystemOptions::DEFAULT_MISSED_CALL_TEXT }}" resize="vertical" />
                            <div class="text-xs text-zinc-400 mt-1">Placeholder: <code class="text-zinc-500">{company}</code></div>
                        </div>
                    </div>

                    {{-- Spam Call Prompt --}}
                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Spam Call Prompt</div>
                                <div class="text-xs text-zinc-500">Played to callers flagged as spam (blocked list, low STIR/SHAKEN attestation, or high spam score) instead of hanging up. Caller can press 2 to send a text or stay on the line for voicemail — this catches misidentified callers.</div>
                            </div>
                            <flux:button size="xs" variant="ghost" icon="play" wire:click="previewTts('spam')" wire:loading.attr="disabled" wire:target="previewTts" title="Preview spam call prompt" />
                        </div>
                        <div>
                            <flux:textarea wire:model="spam_message" rows="3" placeholder="{{ \App\Livewire\Vendors\PhoneSystemOptions::DEFAULT_SPAM }}" resize="vertical" />
                            <div class="text-xs text-zinc-400 mt-1">Placeholders: <code class="text-zinc-500">{company}</code> <code class="text-zinc-500">{greeting}</code></div>
                        </div>
                    </div>

                    {{-- After-Hours Prompt --}}
                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">After-Hours Prompt</div>
                                <div class="text-xs text-zinc-500">Played to callers who reach you outside your Business Hours (set above). Spoken first, then the call goes straight to the Voicemail Menu — the welcome message and team ring are skipped.</div>
                            </div>
                            <flux:button size="xs" variant="ghost" icon="play" wire:click="previewTts('after_hours')" wire:loading.attr="disabled" wire:target="previewTts" title="Preview after-hours prompt" />
                        </div>
                        <div>
                            <flux:textarea wire:model="after_hours_message" rows="2" placeholder="{{ \App\Livewire\Vendors\PhoneSystemOptions::DEFAULT_AFTER_HOURS }}" resize="vertical" />
                            <div class="text-xs text-zinc-400 mt-1">Placeholders: <code class="text-zinc-500">{company}</code> <code class="text-zinc-500">{greeting}</code></div>
                        </div>
                    </div>

                    {{-- Voicemail Menu --}}
                    <div class="space-y-2">
                        <div>
                            <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Voicemail Menu</div>
                            <div class="text-xs text-zinc-500">If no one answers, play an interactive menu: redial, send a text, or leave a voicemail.</div>
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
                                    <flux:textarea wire:model="voicemail_message" rows="2" placeholder="{{ \App\Livewire\Vendors\PhoneSystemOptions::DEFAULT_VOICEMAIL }}" resize="vertical" />
                                    <div class="text-xs text-zinc-400 mt-1">Includes Press 1 redial option. Placeholders: <code class="text-zinc-500">{name}</code> <code class="text-zinc-500">{company}</code> <code class="text-zinc-500">{greeting}</code></div>
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
                                    <flux:textarea wire:model="voicemail_message_unknown" rows="2" placeholder="{{ \App\Livewire\Vendors\PhoneSystemOptions::DEFAULT_VOICEMAIL_UNKNOWN }}" resize="vertical" />
                                    <div class="text-xs text-zinc-400 mt-1">No redial option. Placeholders: <code class="text-zinc-500">{name}</code> <code class="text-zinc-500">{company}</code> <code class="text-zinc-500">{greeting}</code></div>
                                </div>

                                {{-- Press 1: Redial --}}
                                <div class="pl-3">
                                    <div class="flex items-center justify-between gap-2 mb-1">
                                        <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                            <flux:badge size="sm" color="blue" class="mr-1">1</flux:badge> Redial Response
                                        </div>
                                        <flux:button size="xs" variant="ghost" icon="play" wire:click="previewTts('ivr_press1')" wire:loading.attr="disabled" wire:target="previewTts" title="Preview press 1 response" />
                                    </div>
                                    <flux:textarea wire:model="ivr_press1_message" rows="1" placeholder="{{ \App\Livewire\Vendors\PhoneSystemOptions::DEFAULT_IVR_PRESS1 }}" resize="vertical" />
                                    <div class="text-xs text-zinc-400 mt-1">Played before redialing your team. Placeholders: <code class="text-zinc-500">{name}</code> <code class="text-zinc-500">{company}</code> <code class="text-zinc-500">{greeting}</code></div>
                                </div>

                                {{-- Press 2: Send text --}}
                                <div class="pl-3">
                                    <div class="flex items-center justify-between gap-2 mb-1">
                                        <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                            <flux:badge size="sm" color="green" class="mr-1">2</flux:badge> Send Text Response
                                        </div>
                                        <flux:button size="xs" variant="ghost" icon="play" wire:click="previewTts('ivr_press2')" wire:loading.attr="disabled" wire:target="previewTts" title="Preview press 2 response" />
                                    </div>
                                    <flux:textarea wire:model="ivr_press2_message" rows="1" placeholder="{{ \App\Livewire\Vendors\PhoneSystemOptions::DEFAULT_IVR_PRESS2 }}" resize="vertical" />
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
                                    <flux:textarea wire:model="voicemail_greeting" rows="2" placeholder="{{ \App\Livewire\Vendors\PhoneSystemOptions::DEFAULT_VOICEMAIL_GREETING }}" resize="vertical" />
                                    <div class="text-xs text-zinc-400 mt-1">Played before the beep when caller stays on line. Placeholders: <code class="text-zinc-500">{name}</code> <code class="text-zinc-500">{company}</code> <code class="text-zinc-500">{greeting}</code></div>
                                </div>
                            </div>
                    </div>
                </div>
            </flux:field>

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">Save Phone System</span>
                    <span wire:loading wire:target="save">Saving...</span>
                </flux:button>
            </div>
        </form>
    </x-island-card>
</div>
