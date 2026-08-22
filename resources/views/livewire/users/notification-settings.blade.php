<x-island-card heading="Notification Settings" subheading="Choose how and when you receive task notifications." :separator="false">
    <div x-data="notificationSettingsManager()" x-init="init()">
        <flux:accordion transition>
            {{-- Realtime Updates --}}
            <flux:accordion.item expanded>
                <flux:accordion.heading>
                    <span class="flex items-center gap-2">
                        <flux:icon name="bolt" variant="mini" class="size-4 text-zinc-400" />
                        Realtime Updates
                    </span>
                </flux:accordion.heading>
                <flux:accordion.content>
                    <div class="space-y-2">
                        <flux:text class="text-xs">Immediate alerts when tasks change during your notification window.</flux:text>

                        <div class="flex items-center justify-between gap-4 pt-1">
                            <div class="flex items-center gap-2">
                                <flux:icon name="envelope" variant="micro" class="size-3.5 text-zinc-400" />
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">Email</span>
                            </div>
                            <flux:switch wire:model.live="realtime_email" />
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-2">
                                <flux:icon name="device-phone-mobile" variant="micro" class="size-3.5 text-zinc-400" />
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">SMS</span>
                            </div>
                            <flux:switch checked disabled />
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-2">
                                <flux:icon name="computer-desktop" variant="micro" class="size-3.5 text-zinc-400" />
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">Browser</span>
                            </div>
                            <flux:switch x-model="prefs.realtime_browser" x-on:change="handleTypeBrowserToggle('realtime')" />
                        </div>
                        <template x-if="typeBrowserError === 'realtime'">
                            <div class="text-xs text-red-500" x-text="browserError"></div>
                        </template>
                    </div>
                </flux:accordion.content>
            </flux:accordion.item>

            {{-- Morning Digest --}}
            <flux:accordion.item>
                <flux:accordion.heading>
                    <span class="flex items-center gap-2">
                        <flux:icon name="sun" variant="mini" class="size-4 text-zinc-400" />
                        Morning Digest
                    </span>
                </flux:accordion.heading>
                <flux:accordion.content>
                    <div class="space-y-2">
                        <flux:text class="text-xs">Summary of today's tasks, sent at your start time.</flux:text>

                        <div class="flex items-center justify-between gap-4 pt-1">
                            <div class="flex items-center gap-2">
                                <flux:icon name="envelope" variant="micro" class="size-3.5 text-zinc-400" />
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">Email</span>
                            </div>
                            <flux:switch wire:model.live="morning_email" />
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-2">
                                <flux:icon name="device-phone-mobile" variant="micro" class="size-3.5 text-zinc-400" />
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">SMS</span>
                            </div>
                            <flux:switch wire:model.live="morning_sms" />
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-2">
                                <flux:icon name="computer-desktop" variant="micro" class="size-3.5 text-zinc-400" />
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">Browser</span>
                            </div>
                            <flux:switch x-model="prefs.morning_browser" x-on:change="handleTypeBrowserToggle('morning')" />
                        </div>
                        <template x-if="typeBrowserError === 'morning'">
                            <div class="text-xs text-red-500" x-text="browserError"></div>
                        </template>
                    </div>
                </flux:accordion.content>
            </flux:accordion.item>

            {{-- Evening Digest --}}
            <flux:accordion.item>
                <flux:accordion.heading>
                    <span class="flex items-center gap-2">
                        <flux:icon name="moon" variant="mini" class="size-4 text-zinc-400" />
                        Evening Digest
                    </span>
                </flux:accordion.heading>
                <flux:accordion.content>
                    <div class="space-y-2">
                        <flux:text class="text-xs">Tomorrow's tasks, sent at your end time.</flux:text>

                        <div class="flex items-center justify-between gap-4 pt-1">
                            <div class="flex items-center gap-2">
                                <flux:icon name="envelope" variant="micro" class="size-3.5 text-zinc-400" />
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">Email</span>
                            </div>
                            <flux:switch wire:model.live="evening_email" />
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-2">
                                <flux:icon name="device-phone-mobile" variant="micro" class="size-3.5 text-zinc-400" />
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">SMS</span>
                            </div>
                            <flux:switch wire:model.live="evening_sms" />
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <div class="flex items-center gap-2">
                                <flux:icon name="computer-desktop" variant="micro" class="size-3.5 text-zinc-400" />
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">Browser</span>
                            </div>
                            <flux:switch x-model="prefs.evening_browser" x-on:change="handleTypeBrowserToggle('evening')" />
                        </div>
                        <template x-if="typeBrowserError === 'evening'">
                            <div class="text-xs text-red-500" x-text="browserError"></div>
                        </template>
                    </div>
                </flux:accordion.content>
            </flux:accordion.item>

            {{-- Schedule --}}
            <flux:accordion.item>
                <flux:accordion.heading>
                    <span class="flex items-center gap-2">
                        <flux:icon name="clock" variant="mini" class="size-4 text-zinc-400" />
                        Notification Window
                    </span>
                </flux:accordion.heading>
                <flux:accordion.content>
                    <div class="space-y-4">
                        <flux:text class="text-xs">Realtime updates send during this window. Digests send at start/end times.</flux:text>

                        <div class="flex gap-3">
                            <div class="flex flex-col gap-1 flex-1">
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Start Time</div>
                                <flux:input wire:model.live.debounce.500ms="realtime_start" type="time" />
                            </div>
                            <div class="flex flex-col gap-1 flex-1">
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">End Time</div>
                                <flux:input wire:model.live.debounce.500ms="realtime_end" type="time" />
                            </div>
                        </div>
                        <flux:error name="realtime_start" />
                        <flux:error name="realtime_end" />
                    </div>
                </flux:accordion.content>
            </flux:accordion.item>

            {{-- Browser Push — subscription management --}}
            <flux:accordion.item>
                <flux:accordion.heading>
                    <span class="flex items-center gap-2">
                        <flux:icon name="computer-desktop" variant="mini" class="size-4 text-zinc-400" />
                        Browser Push
                    </span>
                </flux:accordion.heading>
                <flux:accordion.content>
                    <div class="space-y-3">
                        <flux:text class="text-xs">Register this browser to receive push notifications.</flux:text>

                        <div class="flex flex-col gap-2">
                            <div class="flex items-center justify-between gap-4">
                                <div>
                                    <div class="flex items-center gap-1.5">
                                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">This Browser</div>
                                        <flux:tooltip position="top">
                                            <flux:icon name="question-mark-circle" variant="micro" class="size-3.5 text-zinc-400 cursor-help" />
                                            <flux:tooltip.content class="max-w-xs">
                                                <div class="space-y-1.5 text-xs">
                                                    <div class="font-semibold">iPhone Setup</div>
                                                    <ol class="list-decimal pl-3.5 space-y-0.5">
                                                        <li>Open this page in <strong>Safari</strong></li>
                                                        <li>Tap the <strong>Share</strong> button (square with arrow)</li>
                                                        <li>Tap <strong>Add to Home Screen</strong></li>
                                                        <li>Open the app from your Home Screen</li>
                                                        <li>Come back here and toggle this on</li>
                                                    </ol>
                                                    <div class="text-zinc-400 pt-0.5">Requires iOS 16.4+ and Safari.</div>
                                                </div>
                                            </flux:tooltip.content>
                                        </flux:tooltip>
                                    </div>
                                    <div class="text-xs text-zinc-500">Activate push notifications for this browser.</div>
                                    <template x-if="browserError && !typeBrowserError">
                                        <div class="text-xs text-red-500 mt-0.5" x-text="browserError"></div>
                                    </template>
                                </div>
                                <flux:switch x-model="prefs.browser_enabled" x-on:change="handleBrowserMasterToggle()" />
                            </div>
                            @include('livewire.users.partials.browser-status-panel', [
                                'showWhen' => 'browserSupported && subscriptions.length > 0',
                                'currentStatus' => 'currentBrowserStatus',
                                'otherStatuses' => 'otherBrowserStatuses',
                            ])
                        </div>

                        @if($user->vendor_role === 'Admin')
                            <flux:separator variant="subtle" />

                            <div class="flex flex-col gap-2">
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Text activity</div>
                                        {{-- "activity", not "incoming": the same flag also fires
                                             when a TEAMMATE texts a thread (outbound job, sender
                                             excluded) — Patryk texting a group notifies Greg. That
                                             is deliberate team visibility; the old "Incoming
                                             Texts" label just denied it. --}}
                                        <div class="text-xs text-zinc-500">Browser alerts for incoming texts and teammates' replies.</div>
                                        <template x-if="smsInboundError">
                                            <div class="text-xs text-red-500 mt-0.5" x-text="smsInboundError"></div>
                                        </template>
                                    </div>
                                    <flux:switch x-model="prefs.sms_inbound_browser" x-on:change="handleSmsInboundBrowserToggle()" />
                                </div>
                                @include('livewire.users.partials.browser-status-panel', [
                                    'showWhen' => 'browserSupported && subscriptions.length > 0',
                                    'currentStatus' => 'currentSmsInboundBrowserStatus',
                                    'otherStatuses' => 'otherSmsInboundBrowserStatuses',
                                ])
                            </div>
                        @endif
                    </div>
                </flux:accordion.content>
            </flux:accordion.item>
        </flux:accordion>
    </div>
</x-island-card>

@script
<script>
Alpine.data('notificationSettingsManager', () => ({
    browserError: null,
    smsInboundError: null,
    typeBrowserError: null,
    browserSupported: true,
    subscriptions: [],
    prefs: {
        browser_enabled: false,
        realtime_browser: false,
        morning_browser: false,
        evening_browser: false,
        sms_inbound_browser: false,
    },

    async init() {
        this.browserSupported = ('Notification' in window) && ('serviceWorker' in navigator) && ('PushManager' in window);
        await this.loadCurrentPrefs();
        await this.loadSubscriptions();
    },

    async loadCurrentPrefs() {
        if (!window.HiveTaskNotifications?.status) {
            return;
        }

        const status = await window.HiveTaskNotifications.status();
        this.browserSupported = status.supported !== false;

        if (status?.enabled && status?.preferences) {
            this.prefs.realtime_browser = Boolean(status.preferences.realtime_enabled);
            this.prefs.morning_browser = Boolean(status.preferences.morning_enabled);
            this.prefs.evening_browser = Boolean(status.preferences.evening_enabled);

            // Master toggle reflects whether this browser has an active push subscription
            this.prefs.browser_enabled = Boolean(status.enabled);

            this.prefs.sms_inbound_browser = Boolean(status.preferences.sms_inbound_enabled);
        }
    },

    async loadSubscriptions() {
        if (!window.HiveTaskNotifications?.listSubscriptions) {
            return;
        }

        const result = await window.HiveTaskNotifications.listSubscriptions();
        this.subscriptions = result?.subscriptions || [];
    },

    get currentBrowserStatus() {
        const current = this.subscriptions.find((s) => s.is_current);
        if (!current) {
            return 'Not registered';
        }

        return `${current.label} (${current.enabled ? 'On' : 'Off'})`;
    },

    get otherBrowserStatuses() {
        return this.subscriptions
            .filter((s) => !s.is_current)
            .map((s) => `${s.label} (${s.enabled ? 'On' : 'Off'})`);
    },

    get currentSmsInboundBrowserStatus() {
        const current = this.subscriptions.find((s) => s.is_current);
        if (!current) {
            return 'Not registered';
        }

        const enabled = Boolean(current?.preferences?.sms_inbound_enabled);

        return `${current.label} (${enabled ? 'On' : 'Off'})`;
    },

    get otherSmsInboundBrowserStatuses() {
        return this.subscriptions
            .filter((s) => !s.is_current)
            .map((s) => {
                const enabled = Boolean(s?.preferences?.sms_inbound_enabled);

                return `${s.label} (${enabled ? 'On' : 'Off'})`;
            });
    },

    async handleBrowserMasterToggle() {
        const enabled = this.prefs.browser_enabled;
        const previous = !enabled;
        this.browserError = null;
        this.typeBrowserError = null;

        if (!this.browserSupported) {
            this.browserError = 'This browser does not support push notifications.';
            this.prefs.browser_enabled = previous;
            return;
        }

        if (enabled) {
            // Register this browser for push
            const granted = await this.ensurePushPermission('browser');
            if (!granted) {
                this.prefs.browser_enabled = previous;
                return;
            }

            if (window.HiveTaskNotifications?.enable) {
                const result = await window.HiveTaskNotifications.enable();
                if (!result?.enabled) {
                    this.browserError = 'Failed to register push subscription. Please try again.';
                    this.prefs.browser_enabled = previous;
                    return;
                }
            }
        } else {
            // Unregister this browser — only if SMS inbound browser is also off
            if (!this.prefs.sms_inbound_browser) {
                if (window.HiveTaskNotifications?.disable) {
                    await window.HiveTaskNotifications.disable();
                }
            }
        }

        await this.loadSubscriptions();

        if (window.Flux?.toast) {
            window.Flux.toast({
                heading: 'Notification settings saved',
                text: enabled ? 'Push notifications activated for this browser.' : 'Push notifications deactivated for this browser.',
                variant: 'success',
                duration: 5000,
            });
        }
    },

    async handleTypeBrowserToggle(type) {
        const key = type + '_browser';
        const enabled = this.prefs[key];
        const previous = !enabled;
        this.browserError = null;
        this.typeBrowserError = null;

        if (!this.browserSupported) {
            this.browserError = 'This browser does not support push notifications.';
            this.typeBrowserError = type;
            this.prefs[key] = previous;
            return;
        }

        if (enabled) {
            // Auto-register this browser if not already registered
            if (!this.prefs.browser_enabled) {
                const granted = await this.ensurePushPermission('browser');
                if (!granted) {
                    this.typeBrowserError = type;
                    this.prefs[key] = previous;
                    return;
                }

                if (window.HiveTaskNotifications?.enable) {
                    const result = await window.HiveTaskNotifications.enable();
                    if (!result?.enabled) {
                        this.browserError = 'Failed to register push subscription. Please try again.';
                        this.typeBrowserError = type;
                        this.prefs[key] = previous;
                        return;
                    }
                    this.prefs.browser_enabled = true;
                }
            }
        }

        // Update only this type's preference
        if (window.HiveTaskNotifications?.updatePreferences) {
            const prefKey = type + '_enabled';
            const update = await window.HiveTaskNotifications.updatePreferences({
                [prefKey]: enabled,
            });

            if (!update?.updated) {
                this.browserError = 'Failed to save browser preferences.';
                this.typeBrowserError = type;
                this.prefs[key] = previous;
                return;
            }
        }

        await this.loadSubscriptions();

        if (window.Flux?.toast) {
            window.Flux.toast({
                heading: 'Notification settings saved',
                text: 'Your preferences have been updated.',
                variant: 'success',
                duration: 5000,
            });
        }
    },

    async handleSmsInboundBrowserToggle() {
        const enabled = this.prefs.sms_inbound_browser;
        const previous = !enabled;
        this.smsInboundError = null;

        if (enabled) {
            // Turning ON — ensure push subscription exists
            if (!this.browserSupported) {
                this.smsInboundError = 'This browser does not support push notifications.';
                this.prefs.sms_inbound_browser = previous;
                return;
            }

            const granted = await this.ensurePushPermission('sms');
            if (!granted) {
                this.prefs.sms_inbound_browser = previous;
                return;
            }

            if (window.HiveTaskNotifications?.enable) {
                const result = await window.HiveTaskNotifications.enable();
                if (!result?.enabled) {
                    this.smsInboundError = 'Failed to register push subscription. Please try again.';
                    this.prefs.sms_inbound_browser = previous;
                    return;
                }
            }

            if (window.HiveTaskNotifications?.updatePreferences) {
                const update = await window.HiveTaskNotifications.updatePreferences({
                    sms_inbound_enabled: true,
                });

                if (!update?.updated) {
                    this.smsInboundError = 'Failed to save browser preferences.';
                    this.prefs.sms_inbound_browser = previous;
                    return;
                }
            }

            await this.loadSubscriptions();
            return;
        }

        if (window.HiveTaskNotifications?.updatePreferences) {
            const update = await window.HiveTaskNotifications.updatePreferences({
                sms_inbound_enabled: false,
            });

            if (!update?.updated) {
                this.smsInboundError = 'Failed to save browser preferences.';
                this.prefs.sms_inbound_browser = previous;
                return;
            }
        }

        // Turning OFF — only remove push subscription if Browser toggle is also off
        if (!this.prefs.browser_enabled) {
            if (window.HiveTaskNotifications?.disable) {
                await window.HiveTaskNotifications.disable();
            }
        }

        await this.loadSubscriptions();
    },

    /**
     * Shared helper: ensure Notification permission is granted.
     * Returns true if granted, false otherwise (sets error on the right toggle).
     */
    async ensurePushPermission(source) {
        let permission = Notification.permission;

        if (permission === 'granted') {
            return true;
        }

        if (permission === 'default') {
            try {
                permission = await Notification.requestPermission();
            } catch (e) {
                const msg = 'Failed to request notification permission. Please try again.';
                if (source === 'sms') { this.smsInboundError = msg; } else { this.browserError = msg; }
                return false;
            }
        }

        if (permission === 'granted') {
            return true;
        }

        if (permission === 'denied') {
            const msg = 'Notifications are blocked. Click the lock/tune icon in the address bar \u2192 Site settings \u2192 Notifications \u2192 Allow, then reload the page.';
            if (source === 'sms') { this.smsInboundError = msg; } else { this.browserError = msg; }
        } else {
            const msg = 'Notification permission was dismissed. Please try again.';
            if (source === 'sms') { this.smsInboundError = msg; } else { this.browserError = msg; }
        }

        return false;
    },
}));
</script>
@endscript
