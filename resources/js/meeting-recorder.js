// Meeting recorder (<livewire:meetings.meeting-recorder>): records an in-person
// meeting from whichever microphone is picked (a clip-on such as the DJI Mic
// Mini plugged into the phone or Surface, or the device's own), and posts the
// audio to Hive in ~10-second pieces while it records, so a dropped connection
// or a closed tab loses seconds, not the meeting. Stop hands the recording to
// the server (MeetingRecorder::finish), which transcribes and summarizes it.
// A recording made elsewhere (Voice Memos on the phone, a handheld recorder)
// is uploaded as a file in the same pieces and takes the same road.
//
// iPhone Safari pauses recording when the screen locks or the tab is left, so
// the screen is kept awake while recording; a Surface keeps recording in the
// background as long as it doesn't sleep.

const MIC_KEY = 'hive.meetingMicId';
const SLICE_MS = 10000;

function pickMimeType() {
    const options = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4;codecs=mp4a.40.2', 'audio/mp4'];

    return options.find((type) => window.MediaRecorder && MediaRecorder.isTypeSupported(type)) || '';
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function readMicPreference() {
    try {
        return localStorage.getItem(MIC_KEY) || '';
    } catch (e) {
        return '';
    }
}

function writeMicPreference(id) {
    try {
        localStorage.setItem(MIC_KEY, id);
    } catch (e) {
        // Private mode: the pick just isn't remembered.
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('meetingRecorder', () => ({
        phase: 'idle', // idle | consent | starting | recording | stopping | upload | uploading
        consent: false,
        file: null,
        progress: 0,
        devices: [],
        deviceId: readMicPreference(),
        seconds: 0,
        maxSeconds: 14400,
        error: '',
        pending: 0,
        recordingId: null,
        uploadUrl: '',
        seq: 0,
        uploads: Promise.resolve(),
        recorder: null,
        stream: null,
        wakeLock: null,
        timer: null,
        hiddenWhileRecording: false,

        get supported() {
            return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
        },

        get clock() {
            const h = Math.floor(this.seconds / 3600);
            const m = Math.floor((this.seconds % 3600) / 60);
            const s = this.seconds % 60;
            const mm = String(m).padStart(h ? 2 : 1, '0');

            return (h ? h + ':' : '') + mm + ':' + String(s).padStart(2, '0');
        },

        async openConsent() {
            this.error = '';
            this.consent = false;
            this.phase = 'consent';
            await this.loadDevices();
        },

        cancelConsent() {
            this.phase = 'idle';
        },

        openUpload() {
            this.error = '';
            this.consent = false;
            this.file = null;
            this.progress = 0;
            this.phase = 'upload';
        },

        cancelUpload() {
            if (this.phase === 'uploading') {
                return;
            }
            this.file = null;
            this.phase = 'idle';
        },

        pickFile(event) {
            this.file = event.target.files && event.target.files[0] ? event.target.files[0] : null;
            this.error = '';
        },

        // How long the file plays, as the browser reads it; the server measures
        // it again once compressed, so 0 here only delays the right answer.
        fileDuration(file) {
            return new Promise((resolve) => {
                const audio = document.createElement('audio');
                const url = URL.createObjectURL(file);
                let settled = false;
                const done = (seconds) => {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    URL.revokeObjectURL(url);
                    resolve(seconds);
                };
                audio.preload = 'metadata';
                audio.onloadedmetadata = () => done(Number.isFinite(audio.duration) ? Math.round(audio.duration) : 0);
                audio.onerror = () => done(0);
                setTimeout(() => done(0), 8000);
                audio.src = url;
            });
        },

        // Post a recording made elsewhere in pieces, the way a live recording
        // is posted, then hand it over to be transcribed.
        async uploadFile() {
            if (!this.file || !this.consent || this.phase === 'uploading') {
                return;
            }

            const file = this.file;
            this.error = '';
            this.phase = 'uploading';
            this.progress = 0;

            const started = await this.$wire.startUpload(true, file.name, file.type || '', file.size, file.lastModified || null);

            if (!started || started.error) {
                this.phase = 'upload';
                this.error = started?.error || 'Hive could not take the file.';

                return;
            }

            const pieceBytes = started.piece_bytes || 5 * 1024 * 1024;
            const pieces = Math.max(1, Math.ceil(file.size / pieceBytes));
            const seconds = await this.fileDuration(file);

            for (let seq = 0; seq < pieces; seq++) {
                const piece = file.slice(seq * pieceBytes, Math.min(file.size, (seq + 1) * pieceBytes), file.type || 'application/octet-stream');

                if (!(await this.upload(seq, piece, 1, started.upload_url))) {
                    await this.$wire.discard(started.id);
                    this.phase = 'upload';
                    this.error = 'The upload stopped partway — check the connection and try again.';

                    return;
                }

                this.progress = Math.round(((seq + 1) / pieces) * 100);
            }

            await this.$wire.finish(started.id, pieces, seconds);
            this.file = null;
            if (this.$refs.uploadFile) {
                this.$refs.uploadFile.value = '';
            }
            this.phase = 'idle';
        },

        // Microphone names only show once the browser has been allowed to use
        // one, so ask once (and let go of it straight away) if they're blank.
        async loadDevices() {
            if (!this.supported) {
                this.error = 'This browser cannot record audio.';

                return;
            }

            try {
                let inputs = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'audioinput');

                if (inputs.length && inputs.every((d) => !d.label)) {
                    const probe = await navigator.mediaDevices.getUserMedia({ audio: true });
                    probe.getTracks().forEach((track) => track.stop());
                    inputs = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'audioinput');
                }

                this.devices = inputs.map((d, i) => ({ id: d.deviceId, label: d.label || 'Microphone ' + (i + 1) }));

                if (!this.devices.some((d) => d.id === this.deviceId)) {
                    // Prefer a plugged-in wireless mic over the built-in one.
                    const wireless = this.devices.find((d) => /dji|rode|røde|hollyland|wireless|usb/i.test(d.label));
                    this.deviceId = (wireless || this.devices[0] || {}).id || '';
                }
            } catch (e) {
                this.error = 'Allow Hive to use the microphone to record (browser settings → microphone).';
            }
        },

        async start() {
            if (!this.consent) {
                this.error = 'Confirm everyone agreed to be recorded first.';

                return;
            }

            this.error = '';
            this.phase = 'starting';

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        deviceId: this.deviceId ? { exact: this.deviceId } : undefined,
                        // Keep every voice in the room: the mic's own noise
                        // cancelling and the transcriber handle the noise.
                        echoCancellation: false,
                        noiseSuppression: false,
                        autoGainControl: true,
                    },
                });
            } catch (e) {
                this.phase = 'consent';
                this.error = 'Could not open that microphone — is it plugged in and switched on?';

                return;
            }

            writeMicPreference(this.deviceId);
            const mimeType = pickMimeType();
            const label = this.stream.getAudioTracks()[0]?.label || '';
            const started = await this.$wire.start(true, mimeType, label);

            if (!started || started.error) {
                this.release();
                this.phase = 'consent';
                this.error = started?.error || 'Hive could not start the recording.';

                return;
            }

            this.recordingId = started.id;
            this.uploadUrl = started.upload_url;
            this.maxSeconds = started.max_seconds || this.maxSeconds;
            this.seq = 0;
            this.seconds = 0;
            this.uploads = Promise.resolve();

            this.recorder = new MediaRecorder(this.stream, mimeType ? { mimeType, audioBitsPerSecond: 64000 } : undefined);
            this.recorder.ondataavailable = (event) => {
                if (event.data && event.data.size > 0) {
                    this.queueUpload(event.data);
                }
            };
            this.recorder.start(SLICE_MS);

            this.phase = 'recording';
            this.timer = setInterval(() => {
                this.seconds++;
                if (this.seconds >= this.maxSeconds) {
                    this.stop();
                }
            }, 1000);
            this.keepAwake();
            window.addEventListener('beforeunload', this.warnBeforeLeaving);
        },

        queueUpload(blob) {
            const seq = this.seq++;
            this.pending++;
            this.uploads = this.uploads.then(() => this.upload(seq, blob)).finally(() => this.pending--);
        },

        // Posts one piece, retrying a few times; true once it is saved.
        async upload(seq, blob, attempt = 1, url = null) {
            const body = new FormData();
            body.append('seq', String(seq));
            body.append('chunk', blob, 'piece-' + seq);

            try {
                const response = await fetch(url || this.uploadUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
                    body,
                    credentials: 'same-origin',
                });

                if (!response.ok && response.status !== 409) {
                    throw new Error('HTTP ' + response.status);
                }

                return true;
            } catch (e) {
                if (attempt >= 6) {
                    this.error = 'Some audio could not be saved — check the connection.';

                    return false;
                }
                await new Promise((resolve) => setTimeout(resolve, 1500 * attempt));

                return this.upload(seq, blob, attempt + 1, url);
            }
        },

        async stop() {
            if (this.phase !== 'recording') {
                return;
            }

            this.phase = 'stopping';
            clearInterval(this.timer);

            await new Promise((resolve) => {
                this.recorder.addEventListener('stop', resolve, { once: true });
                this.recorder.stop();
            });
            await this.uploads;

            await this.$wire.finish(this.recordingId, this.seq, this.seconds);
            this.release();
            this.phase = 'idle';
        },

        async discard() {
            if (!window.confirm('Throw this recording away?')) {
                return;
            }

            clearInterval(this.timer);
            if (this.recorder && this.recorder.state !== 'inactive') {
                this.recorder.ondataavailable = null;
                this.recorder.stop();
            }
            await this.$wire.discard(this.recordingId);
            this.release();
            this.phase = 'idle';
        },

        release() {
            this.stream?.getTracks().forEach((track) => track.stop());
            this.stream = null;
            this.wakeLock?.release?.().catch(() => {});
            this.wakeLock = null;
            window.removeEventListener('beforeunload', this.warnBeforeLeaving);
        },

        async keepAwake() {
            try {
                this.wakeLock = await navigator.wakeLock?.request('screen');
            } catch (e) {
                // Not supported or refused: the recording still runs while the screen is on.
            }
        },

        // A wake lock is dropped whenever the page is hidden; take it again on return.
        onVisibility() {
            if (this.phase !== 'recording') {
                return;
            }
            if (document.hidden) {
                this.hiddenWhileRecording = true;
            } else {
                this.keepAwake();
            }
        },

        warnBeforeLeaving(event) {
            event.preventDefault();
            event.returnValue = '';
        },
    }));
});
