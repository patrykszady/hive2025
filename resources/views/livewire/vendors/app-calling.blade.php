<div class="space-y-6">
    @assets
        <script src="https://cdn.jsdelivr.net/npm/@telnyx/webrtc@2.27.10/lib/bundle.js"></script>
    @endassets

    <x-island-card heading="App Calling" subheading="The Hive Contractors app's calling, in this browser" :separator="true">
        <div x-data="appCalling" class="space-y-5">
            <flux:callout icon="information-circle" color="zinc">
                <flux:callout.text>
                    While this page is open, your incoming business calls ring here as well as on your cell. Answering in one place stops the other. Calls you place here go out from the business number, recorded and logged like any Hive call.
                </flux:callout.text>
            </flux:callout>

            <div class="flex items-center gap-2 text-sm">
                <span class="size-2 rounded-full" :class="ready ? 'bg-green-500' : 'bg-zinc-300 dark:bg-zinc-600'"></span>
                <span class="text-zinc-700 dark:text-zinc-300" x-text="status"></span>
            </div>

            <template x-if="incoming">
                <div class="flex flex-wrap items-center gap-3 rounded-lg border border-green-200 bg-green-50 p-3 dark:border-green-800 dark:bg-green-950">
                    <flux:icon.phone-arrow-down-left class="size-5 text-green-600" />
                    <span class="flex-1 text-sm font-medium text-zinc-900 dark:text-white" x-text="incomingLabel"></span>
                    <flux:button size="sm" variant="primary" color="green" icon="phone" x-on:click="answer()">Answer</flux:button>
                    <flux:button size="sm" variant="danger" x-on:click="decline()">Decline</flux:button>
                </div>
            </template>

            <template x-if="call">
                <div class="flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <flux:icon.phone class="size-5 text-green-600" />
                    <span class="flex-1 text-sm font-medium text-zinc-900 dark:text-white">In a call</span>
                    <flux:button size="sm" x-on:click="toggleMute()" x-text="muted ? 'Unmute' : 'Mute'"></flux:button>
                    <flux:button size="sm" variant="danger" icon="phone-x-mark" x-on:click="hangup()">Hang up</flux:button>
                </div>
            </template>

            <form x-on:submit.prevent="dial()" class="flex items-end gap-2">
                <flux:input wire:model="number" label="Call a number" placeholder="(847) 555-0100" class="max-w-xs" />
                <flux:button type="submit" variant="primary" icon="phone" x-bind:disabled="!ready || call">Call</flux:button>
            </form>
            <flux:error name="number" />

            <audio id="hive-remote-audio" autoplay class="hidden"></audio>
        </div>
    </x-island-card>
</div>

@script
<script>
    Alpine.data('appCalling', () => ({
        client: null,
        ready: false,
        status: 'Signing in…',
        call: null,
        incoming: null,
        muted: false,
        outboundRequestedAt: 0,

        get incomingLabel() {
            const options = this.incoming?.options || {};

            return 'Call from ' + (options.remoteCallerName || options.remoteCallerNumber || 'unknown');
        },

        async init() {
            const signIn = await $wire.token();
            if (! signIn.ok) {
                this.status = signIn.error;
                return;
            }

            this.client = new TelnyxWebRTC.TelnyxRTC({ login_token: signIn.token });
            this.client.remoteElement = 'hive-remote-audio';
            this.client.on('telnyx.ready', () => { this.ready = true; this.status = 'Ready: your calls ring here too'; });
            this.client.on('telnyx.error', (event) => { this.ready = false; this.status = 'Calling error: ' + (event?.error?.message || 'see the browser console'); });
            this.client.on('telnyx.socket.close', () => { this.ready = false; this.status = 'Disconnected; reload the page'; });
            this.client.on('telnyx.notification', (notification) => this.onNotification(notification));
            this.client.connect();
        },

        destroy() {
            this.client?.disconnect();
        },

        onNotification(notification) {
            if (notification.type !== 'callUpdate') {
                return;
            }

            const call = notification.call;

            if (call.state === 'ringing' && call.direction === 'inbound') {
                const headers = call.options?.customHeaders || [];
                const isOurOutbound = headers.some((header) => header.name === 'X-Hive-Outbound-Call')
                    || (Date.now() - this.outboundRequestedAt < 20000);

                if (isOurOutbound) {
                    this.outboundRequestedAt = 0;
                    this.status = 'Calling…';
                    call.answer();
                    return;
                }

                this.incoming = call;
                this.status = this.incomingLabel;
                return;
            }

            if (call.state === 'active') {
                this.call = call;
                this.incoming = null;
                this.status = 'In a call';
            }

            if (call.state === 'hangup' || call.state === 'destroy') {
                if (this.call?.id === call.id) { this.call = null; this.muted = false; }
                if (this.incoming?.id === call.id) { this.incoming = null; }
                if (this.ready) { this.status = 'Ready: your calls ring here too'; }
            }
        },

        async dial() {
            this.outboundRequestedAt = Date.now();
            this.status = 'Placing the call…';
            const result = await $wire.callOut();
            if (! result.ok) {
                this.outboundRequestedAt = 0;
                this.status = result.error || 'The call could not be placed';
            }
        },

        answer() { this.incoming?.answer(); },
        decline() { this.incoming?.hangup(); this.incoming = null; },
        hangup() { this.call?.hangup(); },
        toggleMute() {
            if (! this.call) { return; }
            this.muted ? this.call.unmuteAudio() : this.call.muteAudio();
            this.muted = ! this.muted;
        },
    }));
</script>
@endscript
