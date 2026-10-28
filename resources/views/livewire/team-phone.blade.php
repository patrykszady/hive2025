{{-- The Hive phone, persisted across pages (App\Livewire\TeamPhone). --}}
<div>
@if ($enabled)
    @assets
        <script src="https://cdn.jsdelivr.net/npm/@telnyx/webrtc@2.27.10/lib/bundle.js"></script>
    @endassets

    <div x-data="teamPhone" x-on:team-call.window="callUser($event.detail.userId)" x-on:team-call-crew.window="callCrew()" class="print:hidden" data-team-phone>
        <audio id="hive-team-phone-audio" autoplay class="hidden"></audio>

        {{-- Ringing: a teammate (or a business caller) --}}
        <template x-if="incoming">
            <div class="fixed bottom-4 right-4 z-50 flex w-[22rem] max-w-[calc(100vw-2rem)] items-center gap-3 rounded-xl border border-green-200 bg-white p-3 shadow-xl dark:border-green-800 dark:bg-zinc-900" role="alert" data-team-phone-ringing>
                <span class="relative flex size-10 shrink-0 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/40">
                    <span class="absolute inline-flex size-full animate-ping rounded-full bg-green-300 opacity-60 dark:bg-green-700"></span>
                    <flux:icon.phone-arrow-down-left class="relative size-5 text-green-700 dark:text-green-300" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white" x-text="incoming.name"></p>
                    <p class="text-xs text-zinc-500" x-text="incoming.crew ? 'Calling the crew in Hive' : (incoming.internal ? 'Calling you in Hive' : 'Business line')"></p>
                </div>
                <flux:button size="sm" variant="primary" color="green" icon="phone" x-on:click="answer()">Answer</flux:button>
                <flux:button size="sm" variant="danger" icon="phone-x-mark" x-on:click="decline()" square aria-label="Decline"></flux:button>
            </div>
        </template>

        {{-- Calling someone, or in a call --}}
        <template x-if="!incoming && (call || dialing)">
            <div class="fixed bottom-4 right-4 z-50 flex w-[22rem] max-w-[calc(100vw-2rem)] items-center gap-3 rounded-xl border border-zinc-200 bg-white p-3 shadow-xl dark:border-zinc-700 dark:bg-zinc-900" data-team-phone-call>
                <flux:icon.phone class="size-5 shrink-0" x-bind:class="call ? 'text-green-600' : 'text-zinc-400 animate-pulse'" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white" x-text="dialing ? ('Calling ' + dialing + '…') : 'In a call'"></p>
                    <p class="text-xs text-zinc-500" x-text="status"></p>
                </div>
                <template x-if="call">
                    <flux:button size="sm" x-on:click="toggleMute()" x-text="muted ? 'Unmute' : 'Mute'"></flux:button>
                </template>
                <flux:button size="sm" variant="danger" icon="phone-x-mark" x-on:click="hangup()" square aria-label="Hang up"></flux:button>
            </div>
        </template>

        {{-- A word when something went wrong (hidden when idle; sits above the ring or call panel) --}}
        <template x-if="!call && notice">
            <div class="fixed right-4 z-50 flex w-[22rem] max-w-[calc(100vw-2rem)] items-center gap-2 rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 shadow-lg dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200" x-bind:class="(incoming || dialing) ? 'bottom-24' : 'bottom-4'" x-on:click="notice = null" data-team-phone-notice>
                <flux:icon.information-circle class="size-4 text-zinc-400" />
                <span x-text="notice"></span>
            </div>
        </template>
    </div>

    @script
    <script>
        Alpine.data('teamPhone', () => ({
            client: null,
            ready: false,
            status: '',
            notice: null,
            call: null,
            incoming: null,      // { id, name, internal, webrtc }  — webrtc is the ringing SDK call when this tab was rung
            dialing: null,       // the name being called
            muted: false,
            outboundRequestedAt: 0,
            pendingAnswer: null, // call log id to answer as soon as the client is ready
            connecting: null,
            placing: false,      // a Call tap in flight — a second tap within 4 s is ignored

            async init() {
                this.readUrl();
                document.addEventListener('livewire:navigated', () => this.readUrl());
                await this.connect();
            },

            destroy() { this.client?.disconnect(); },

            async connect() {
                if (this.connecting) { return this.connecting; }
                this.connecting = (async () => {
                    const signIn = await $wire.token();
                    if (! signIn.ok) { this.notice = signIn.error; return; }
                    this.client = new TelnyxWebRTC.TelnyxRTC({ login_token: signIn.token });
                    this.client.remoteElement = 'hive-team-phone-audio';
                    this.client.on('telnyx.ready', () => { this.ready = true; this.status = ''; this.flushPendingAnswer(); });
                    this.client.on('telnyx.error', (event) => {
                        const message = event?.error?.message || '';
                        // A microphone problem reads as one, not as a calling error.
                        const mic = this.microphoneMessage(event?.error);
                        if (mic) { this.notice = mic; this.dialing = null; this.outboundRequestedAt = 0; return; }
                        this.ready = false; this.notice = 'Calling error: ' + (message || 'see the browser console');
                    });
                    this.client.on('telnyx.socket.close', () => { this.ready = false; });
                    this.client.on('telnyx.notification', (n) => this.onNotification(n));
                    this.client.connect();
                })();
                return this.connecting;
            },

            // ?answerCall=ID (the push's Answer), ?incomingCall=ID (a tap on the
            // push), ?callBack=USER (a missed-call push) — then the URL is cleaned.
            readUrl() {
                const params = new URLSearchParams(location.search);
                const answerCall = params.get('answerCall');
                const incomingCall = params.get('incomingCall');
                const callBack = params.get('callBack');
                if (! answerCall && ! incomingCall && ! callBack) { return; }
                ['answerCall', 'incomingCall', 'callBack'].forEach((k) => params.delete(k));
                const clean = location.pathname + (params.toString() ? '?' + params.toString() : '') + location.hash;
                history.replaceState(history.state, '', clean);
                if (answerCall) { this.pendingAnswer = parseInt(answerCall, 10); this.flushPendingAnswer(); }
                else if (incomingCall) { this.showServerRing(parseInt(incomingCall, 10)); }
                else if (callBack) { this.callUser(parseInt(callBack, 10)); }
            },

            // Ask the browser for the microphone before anything rings — the
            // permission prompt appears here, on the click, instead of a bare
            // "no mic found" from the SDK once the call is already connecting.
            async ensureMicrophone() {
                if (! navigator.mediaDevices?.getUserMedia) {
                    this.notice = 'This browser cannot use a microphone here — open Hive over HTTPS in Chrome, Safari or Edge.';
                    return false;
                }
                // Already allowed: no extra prompt, no extra capture — the SDK opens the
                // mic itself when the call connects. iPhone Safari cannot answer this
                // question and prompts on every capture, so there the SDK's own prompt
                // is the only one (a probe here meant "allow mic" twice and a "stop
                // recording" alert after the probe's stream was closed).
                try {
                    if (! navigator.permissions?.query) { return true; }
                    const state = (await navigator.permissions.query({ name: 'microphone' })).state;
                    if (state === 'granted') { return true; }
                } catch (e) { return true; }
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    stream.getTracks().forEach((track) => track.stop());
                    return true;
                } catch (error) {
                    this.notice = this.microphoneMessage(error) || ('Microphone error: ' + (error?.message || error?.name || 'unknown'));
                    // "Not found" with no input device listed at all is nearly always the
                    // operating system keeping the browser away from the mic, not a missing mic.
                    if (/No microphone found/.test(this.notice)) {
                        try {
                            const inputs = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'audioinput');
                            this.notice = inputs.length === 0
                                ? 'The browser sees no microphone. On Windows: Settings → Privacy & security → Microphone → turn on "Microphone access" and "Let desktop apps access your microphone"; on a Mac: System Settings → Privacy & Security → Microphone → allow the browser. Then try again.'
                                : 'Your microphone (' + (inputs[0].label || 'default') + ') could not be opened — check the browser\'s site settings (lock icon → Microphone → Allow) and that no other app holds it, then try again.';
                        } catch (e) {}
                    }
                    return false;
                }
            },

            microphoneMessage(error) {
                const name = String(error?.name || '');
                const text = String(error?.message || '').toLowerCase();
                if (name === 'NotAllowedError' || name === 'PermissionDeniedError' || name === 'SecurityError' || /permission|not allowed|denied/.test(text)) {
                    return 'Allow the microphone for hive.contractors — click the lock icon in the address bar, set Microphone to Allow, then try again.';
                }
                if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError' || /no mic|microphone|requested device not found|device not found/.test(text)) {
                    return 'No microphone found — plug one in (or pick one in your system sound settings), then try again.';
                }
                if (name === 'NotReadableError' || name === 'AbortError' || /in use|could not start/.test(text)) {
                    return 'The microphone is in use by another app — close it, then try again.';
                }
                return null;
            },

            async showServerRing(callLogId) {
                const ring = await $wire.ringing(callLogId);
                if (ring.ok) { this.incoming = { id: callLogId, name: ring.name, internal: true, crew: !! ring.crew, webrtc: null }; }
                else { this.notice = 'That call has ended.'; }
            },

            async flushPendingAnswer() {
                if (! this.pendingAnswer || ! this.ready) { return; }
                const id = this.pendingAnswer; this.pendingAnswer = null;
                // The push's Answer opened Hive: the mic prompt comes first; a refusal
                // leaves the ring banner up so Answer can be pressed again once allowed.
                if (! await this.ensureMicrophone()) { this.showServerRing(id); return; }
                await this.answerFromServer(id);
            },

            // Ask Hive to ring this login into the waiting call; the ring is
            // answered automatically (X-Hive-Outbound-Call) when it arrives.
            async answerFromServer(callLogId, attempt = 0) {
                this.outboundRequestedAt = Date.now();
                this.status = 'Connecting…'; this.dialing = this.incoming?.name || 'back';
                const result = await $wire.answer(callLogId);
                if (result.ok) { this.incoming = null; return; }
                if (result.error === 'not_ready' && attempt < 4) { setTimeout(() => this.answerFromServer(callLogId, attempt + 1), 1200); return; }
                this.outboundRequestedAt = 0; this.dialing = null; this.incoming = null;
                this.notice = result.error || 'That call has ended.';
            },

            onNotification(notification) {
                if (notification.type !== 'callUpdate') { return; }
                const call = notification.call;

                if (call.state === 'ringing' && call.direction === 'inbound') {
                    const headers = call.options?.customHeaders || [];
                    const header = (name) => headers.find((h) => h.name === name)?.value;
                    // Hive rang this login because THIS device asked (Call, Answer): answer it
                    // here. The same login is registered on every device with Hive open, so
                    // the ring forks to all of them — the others ignore it, or a phone in a
                    // pocket would take the call and the desk would sit silent.
                    const requestedHere = Date.now() - this.outboundRequestedAt < 20000;
                    if (header('X-Hive-Outbound-Call')) {
                        if (! requestedHere) { return; }
                        this.outboundRequestedAt = 0; this.status = 'Connecting…'; call.answer(); return;
                    }
                    if (requestedHere) { this.outboundRequestedAt = 0; this.status = 'Connecting…'; call.answer(); return; }
                    const callLogId = header('X-Hive-Call-Log') ? parseInt(header('X-Hive-Call-Log'), 10) : null;
                    const name = call.options?.remoteCallerName || call.options?.remoteCallerNumber || 'Unknown caller';
                    const internal = !! callLogId && /^[A-Za-z]/.test(name) && ! /^\+?\d/.test(name) && (call.options?.remoteCallerNumber || '').replace(/\D/g, '') === '{{ preg_replace('/\D/', '', (string) config('services.telnyx.from')) }}';
                    this.incoming = { id: callLogId, name, internal: this.incoming?.internal ?? internal, crew: this.incoming?.crew ?? false, webrtc: call };
                    // A crew call says so on the banner; the SIP ring alone cannot tell.
                    if (callLogId && internal) { $wire.ringing(callLogId).then((ring) => { if (ring.ok && this.incoming?.id === callLogId) { this.incoming.crew = !! ring.crew; } }).catch(() => {}); }
                    try { navigator.vibrate?.([200, 100, 200]); } catch (e) {}
                    return;
                }

                if (call.state === 'active') {
                    this.call = call; this.incoming = null; this.dialing = null; this.status = 'Connected';
                    // Belt and braces for the remote audio: attach the stream to our element
                    // ourselves and start it, in case the SDK's remoteElement did not.
                    try {
                        const el = document.getElementById('hive-team-phone-audio');
                        if (el && call.remoteStream && el.srcObject !== call.remoteStream) { el.srcObject = call.remoteStream; }
                        el?.play?.().catch(() => {});
                    } catch (e) {}
                }

                if (call.state === 'hangup' || call.state === 'destroy') {
                    if (this.call?.id === call.id) { this.call = null; this.muted = false; this.status = ''; this.notice = 'Call ended'; setTimeout(() => { if (this.notice === 'Call ended') this.notice = null; }, 4000); }
                    if (this.incoming?.webrtc?.id === call.id) { this.incoming = null; }
                    if (this.dialing && ! this.call) { this.dialing = null; }
                }
            },

            async callUser(userId) {
                if (! userId) { return; }
                if (this.call || this.dialing || this.placing) { this.notice = 'You are already on a call — hang up first.'; return; }
                this.placing = true; setTimeout(() => { this.placing = false; }, 4000);
                if (! await this.ensureMicrophone()) { this.placing = false; return; }
                await this.connect();
                this.outboundRequestedAt = Date.now();
                this.status = 'Ringing your Hive…';
                const result = await $wire.callUser(userId);
                if (! result.ok) { this.outboundRequestedAt = 0; this.dialing = null; this.notice = result.error || 'The call could not be placed'; return; }
                this.dialing = result.name; this.status = 'Ringing ' + result.name + ' in Hive…';
            },

            async callCrew() {
                if (this.call || this.dialing || this.placing) { this.notice = 'You are already on a call — hang up first.'; return; }
                this.placing = true; setTimeout(() => { this.placing = false; }, 4000);
                if (! await this.ensureMicrophone()) { this.placing = false; return; }
                await this.connect();
                this.outboundRequestedAt = Date.now();
                this.status = 'Ringing your Hive…';
                const result = await $wire.callCrew();
                if (! result.ok) { this.outboundRequestedAt = 0; this.dialing = null; this.notice = result.error || 'The call could not be placed'; return; }
                this.dialing = result.name; this.status = 'Ringing the crew in Hive…';
            },

            async answer() {
                const ring = this.incoming; if (! ring) { return; }
                if (! await this.ensureMicrophone()) { return; }
                this.notice = null;
                if (ring.webrtc) { ring.webrtc.answer(); this.incoming = null; return; }
                this.answerFromServer(ring.id);
            },
            decline() {
                const ring = this.incoming; if (! ring) { return; }
                this.incoming = null;
                if (ring.webrtc) { try { ring.webrtc.hangup(); } catch (e) {} }
                if (ring.internal && ring.id) { $wire.decline(ring.id); }
            },
            hangup() { this.call?.hangup(); this.dialing = null; this.outboundRequestedAt = 0; },
            toggleMute() { if (! this.call) { return; } this.muted ? this.call.unmuteAudio() : this.call.muteAudio(); this.muted = ! this.muted; },
        }));
    </script>
    @endscript
@endif
</div>
