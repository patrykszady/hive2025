<x-island-card heading="Notification Settings" subheading="Choose how and when you receive task notifications." :separator="true">
    <div class="space-y-4" x-data="notificationSettingsManager()" x-init="init()">

        {{-- ─── Realtime Updates ─── --}}
        <flux:field>
            <flux:label>Realtime Updates</flux:label>
            <flux:description>Get notified immediately when tasks change during this time window.</flux:description>

            <div class="mt-2 flex flex-col gap-3">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Email</div>
                        <div class="text-xs text-zinc-500">Receive realtime updates via email.</div>
                    </div>
                    <flux:switch wire:model.live="realtime_email" />
                </div>
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Browser</div>
                        <div class="text-xs text-zinc-500">Push notifications in this browser.</div>
                        <template x-if="browserError">
                            <div class="text-xs text-red-500 mt-0.5" x-text="browserError"></div>
                        </template>
                    </div>
                    <flux:switch x-model="prefs.realtime_enabled" x-on:change="handleBrowserToggle('realtime_enabled')" />
                </div>
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Text (SMS)</div>
                        <div class="text-xs text-zinc-500">Receive realtime updates via text message.</div>
                    </div>
                    <flux:switch wire:model.live="realtime_sms" />
                </div>

                <div class="rounded-md border border-zinc-200 dark:border-zinc-700 px-3 py-2 text-xs text-zinc-600 dark:text-zinc-300" x-show="browserSupported && subscriptions.length > 0">
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

                <div x-show="$wire.realtime_email || $wire.realtime_sms || prefs.realtime_enabled" x-cloak>
                    <div class="grid grid-cols-2 gap-4 pt-1">
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
                </div>
            </div>
        </flux:field>

        <flux:separator />

        {{-- ─── Morning Digest ─── --}}
        <flux:field>
            <flux:label>Morning Digest</flux:label>
            <flux:description>Receive a summary of today's tasks each morning.</flux:description>

            <div class="mt-1 flex flex-col gap-2">
                <div class="flex items-center justify-between gap-4">
                    <div class="text-sm text-zinc-700 dark:text-zinc-300">Email</div>
                    <flux:switch wire:model.live="morning_email" />
                </div>
                <div class="flex items-center justify-between gap-4">
                    <div class="text-sm text-zinc-700 dark:text-zinc-300">Browser</div>
                    <flux:switch x-model="prefs.morning_enabled" x-on:change="handleBrowserToggle('morning_enabled')" />
                </div>
                <div class="flex items-center justify-between gap-4">
                    <div class="text-sm text-zinc-700 dark:text-zinc-300">Text (SMS)</div>
                    <flux:switch wire:model.live="morning_sms" />
                </div>
            </div>
        </flux:field>

        <flux:separator />

        {{-- ─── Evening Digest ─── --}}
        <flux:field>
            <flux:label>Evening Digest</flux:label>
            <flux:description>Receive a summary of tomorrow's scheduled tasks each evening.</flux:description>

            <div class="mt-1 flex flex-col gap-2">
                <div class="flex items-center justify-between gap-4">
                    <div class="text-sm text-zinc-700 dark:text-zinc-300">Email</div>
                    <flux:switch wire:model.live="evening_email" />
                </div>
                <div class="flex items-center justify-between gap-4">
                    <div class="text-sm text-zinc-700 dark:text-zinc-300">Browser</div>
                    <flux:switch x-model="prefs.evening_enabled" x-on:change="handleBrowserToggle('evening_enabled')" />
                </div>
                <div class="flex items-center justify-between gap-4">
                    <div class="text-sm text-zinc-700 dark:text-zinc-300">Text (SMS)</div>
                    <flux:switch wire:model.live="evening_sms" />
                </div>
            </div>
        </flux:field>
    </div>
</x-island-card>

@script
<script>
Alpine.data('notificationSettingsManager', () => ({
    browserError: null,
    browserSupported: true,
    subscriptions: [],
    prefs: {
        realtime_enabled: false,
        morning_enabled: false,
        evening_enabled: false,
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
            this.prefs = {
                realtime_enabled: Boolean(status.preferences.realtime_enabled),
                morning_enabled: Boolean(status.preferences.morning_enabled),
                evening_enabled: Boolean(status.preferences.evening_enabled),
            };
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

    async handleBrowserToggle(key) {
        const previous = { ...this.prefs };
        this.browserError = null;

        if (!this.browserSupported) {
            this.browserError = 'This browser does not support push notifications.';
            this.prefs = previous;
            return;
        }

        // When enabling, ensure push subscription + permission exist
        if (this.prefs[key]) {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                this.browserError = permission === 'denied'
                    ? 'Notifications are blocked. Please enable them in your browser settings.'
                    : 'Notification permission was dismissed. Please try again.';
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

        // Save per-subscription preferences
        if (window.HiveTaskNotifications?.updatePreferences) {
            const update = await window.HiveTaskNotifications.updatePreferences({
                realtime_enabled: this.prefs.realtime_enabled,
                morning_enabled: this.prefs.morning_enabled,
                evening_enabled: this.prefs.evening_enabled,
            });

            if (!update?.updated) {
                this.browserError = 'Failed to save browser preferences.';
                this.prefs = previous;
                return;
            }
        }

        // Unsubscribe if all browser toggles are off
        if (!this.prefs.realtime_enabled && !this.prefs.morning_enabled && !this.prefs.evening_enabled) {
            if (window.HiveTaskNotifications?.disable) {
                await window.HiveTaskNotifications.disable();
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
}));
</script>
@endscript
