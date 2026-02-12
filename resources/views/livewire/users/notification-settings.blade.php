<x-island-card heading="Notification Settings" subheading="Choose how and when you receive task notifications." :separator="true">
    <div class="space-y-4" x-data="notificationSettingsManager()" x-init="init()">

        {{-- ─── Delivery Channels ─── --}}
        <flux:field>
            <flux:label>Delivery Channels</flux:label>
            <flux:description>Choose how you want to receive notifications.</flux:description>

            <div class="mt-2 flex flex-col gap-3">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Email</div>
                        <div class="text-xs text-zinc-500">Receive updates via email.</div>
                    </div>
                    <flux:switch wire:model.live="channel_email" />
                </div>
                <div class="flex flex-col gap-2">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Browser</div>
                            <div class="text-xs text-zinc-500">Push notifications in this browser.</div>
                            <template x-if="browserError">
                                <div class="text-xs text-red-500 mt-0.5" x-text="browserError"></div>
                            </template>
                        </div>
                        <flux:switch x-model="prefs.browser_enabled" x-on:change="handleBrowserMasterToggle()" />
                    </div>
                    <div class="rounded-md border border-zinc-200 dark:border-zinc-700 px-3 py-2 text-xs text-zinc-600 dark:text-zinc-300" x-show="browserSupported && subscriptions.length > 0" x-cloak>
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-zinc-800 dark:text-zinc-100">This browser:</span>
                            <span x-text="currentBrowserStatus"></span>
                        </div>
                        <template x-if="otherBrowserStatuses.length">
                            <div class="mt-1">
                                <span class="font-medium text-zinc-800 dark:text-zinc-100">Other browsers:</span>
                                <span x-text="otherBrowserStatuses.join(', ')"></span>
                            </div>
                        </template>
                    </div>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Text (SMS)</div>
                        <div class="text-xs text-zinc-500">Receive updates via text message.</div>
                    </div>
                    <flux:switch wire:model.live="channel_sms" />
                </div>
                @if($user->vendor_role === 'Admin')
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">New Text Alerts (Browser)</div>
                            <div class="text-xs text-zinc-500">Show browser notifications when inbound SMS/MMS messages arrive.</div>
                            <template x-if="smsInboundError">
                                <div class="text-xs text-red-500 mt-0.5" x-text="smsInboundError"></div>
                            </template>
                        </div>
                        <flux:switch x-model="$wire.sms_inbound_browser" x-on:change="handleSmsInboundBrowserToggle()" />
                    </div>
                @endif
            </div>
        </flux:field>

        <flux:separator />

        {{-- ─── Time Window ─── --}}
        <flux:field>
            <flux:label>Notification Window</flux:label>
            <flux:description>Realtime updates are sent during this window. Morning Digest sends at your start time. Evening Digest sends at your end time with tomorrow's tasks.</flux:description>

            <div class="grid grid-cols-2 gap-4 mt-2">
                <flux:input
                    wire:model.live.debounce.500ms="realtime_start"
                    type="time"
                    label="Start Time"
                />
                <flux:input
                    wire:model.live.debounce.500ms="realtime_end"
                    type="time"
                    label="End Time"
                />
            </div>
            <flux:error name="realtime_start" />
            <flux:error name="realtime_end" />
        </flux:field>

        <flux:separator />

        {{-- ─── Notification Types ─── --}}
        <flux:field>
            <flux:label>Notification Types</flux:label>

            <div class="mt-2 flex flex-col gap-3">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Realtime Updates</div>
                        <div class="text-xs text-zinc-500">Get notified immediately when tasks change during your time window.</div>
                    </div>
                    <flux:switch wire:model.live="type_realtime" />
                </div>
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Morning Digest</div>
                        <div class="text-xs text-zinc-500">Receive a summary of today's tasks at your start time.</div>
                    </div>
                    <flux:switch wire:model.live="type_morning" />
                </div>
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Evening Digest</div>
                        <div class="text-xs text-zinc-500">Receive a summary of tomorrow's tasks at your end time.</div>
                    </div>
                    <flux:switch wire:model.live="type_evening" />
                </div>
            </div>
        </flux:field>
    </div>
</x-island-card>

@script
<script>
Alpine.data('notificationSettingsManager', () => ({
    browserError: null,
    smsInboundError: null,
    browserSupported: true,
    subscriptions: [],
    prefs: {
        browser_enabled: false,
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
            // Browser is enabled if any of the individual browser prefs were on
            this.prefs.browser_enabled = Boolean(
                status.preferences.realtime_enabled ||
                status.preferences.morning_enabled ||
                status.preferences.evening_enabled
            );
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

    async handleBrowserMasterToggle() {
        const previous = { ...this.prefs };
        this.browserError = null;

        if (!this.browserSupported) {
            this.browserError = 'This browser does not support push notifications.';
            this.prefs = previous;
            return;
        }

        if (this.prefs.browser_enabled) {
            const granted = await this.ensurePushPermission('browser');
            if (!granted) {
                this.prefs = previous;
                return;
            }

            if (window.HiveTaskNotifications?.enable) {
                const result = await window.HiveTaskNotifications.enable();
                if (!result?.enabled) {
                    this.browserError = 'Failed to register push subscription. Please try again.';
                    this.prefs = previous;
                    return;
                }
            }
        }

        // Set all browser prefs to the master toggle value
        const enabled = this.prefs.browser_enabled;
        if (window.HiveTaskNotifications?.updatePreferences) {
            const update = await window.HiveTaskNotifications.updatePreferences({
                realtime_enabled: enabled,
                morning_enabled: enabled,
                evening_enabled: enabled,
            });

            if (!update?.updated) {
                this.browserError = 'Failed to save browser preferences.';
                this.prefs = previous;
                return;
            }
        }

        if (!enabled) {
            // Only fully remove push subscription if SMS inbound browser is also off
            if (!$wire.sms_inbound_browser) {
                if (window.HiveTaskNotifications?.disable) {
                    await window.HiveTaskNotifications.disable();
                }
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
        this.smsInboundError = null;

        if ($wire.sms_inbound_browser) {
            // Turning ON — ensure push subscription exists
            if (!this.browserSupported) {
                this.smsInboundError = 'This browser does not support push notifications.';
                $wire.sms_inbound_browser = false;
                return;
            }

            const granted = await this.ensurePushPermission('sms');
            if (!granted) {
                $wire.sms_inbound_browser = false;
                return;
            }

            if (window.HiveTaskNotifications?.enable) {
                const result = await window.HiveTaskNotifications.enable();
                if (!result?.enabled) {
                    this.smsInboundError = 'Failed to register push subscription. Please try again.';
                    $wire.sms_inbound_browser = false;
                    return;
                }
            }

            await this.loadSubscriptions();
            // Explicitly save after async work completes
            await $wire.save();
        } else {
            // Turning OFF — only remove push subscription if Browser toggle is also off
            if (!this.prefs.browser_enabled) {
                if (window.HiveTaskNotifications?.disable) {
                    await window.HiveTaskNotifications.disable();
                }
                await this.loadSubscriptions();
            }
            // Explicitly save the OFF state
            await $wire.save();
        }
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
