{{-- In-person meeting recorder (App\Livewire\Meetings\MeetingRecorder). The
     recording itself runs in the browser (resources/js/meeting-recorder.js);
     x-data stays static so a re-render never resets a running recording. --}}
<div x-data="meetingRecorder" x-on:visibilitychange.document="onVisibility()" data-meeting-recorder>
    <x-island-card heading="Meeting recordings" subheading="Record an in-person meeting — Hive writes the summary and adds the action items to the planner.">
        <x-slot:actions>
            <div x-show="phase === 'idle'" class="flex items-center gap-2">
                <flux:button size="sm" variant="ghost" icon="arrow-up-tray" x-on:click="openUpload()">Upload</flux:button>
                <flux:button size="sm" variant="primary" icon="microphone" x-on:click="openConsent()">Record</flux:button>
            </div>
        </x-slot:actions>

        <div class="space-y-3 pb-3">
            {{-- Before recording: which microphone, and everyone's consent. --}}
            <div x-show="phase === 'consent' || phase === 'starting'" x-cloak class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-white/10">
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-800 dark:text-white">Microphone</label>
                    <select
                        x-model="deviceId"
                        class="block w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-800 shadow-xs dark:border-white/10 dark:bg-white/10 dark:text-zinc-200"
                    >
                        <template x-for="device in devices" :key="device.id">
                            <option :value="device.id" x-text="device.label" :selected="device.id === deviceId"></option>
                        </template>
                    </select>
                    <flux:text size="sm" class="mt-1">A plugged-in wireless mic (like the DJI Mic Mini) shows up here; Hive remembers your pick.</flux:text>
                </div>

                <label class="flex items-start gap-2 text-sm text-zinc-800 dark:text-zinc-200">
                    <input type="checkbox" x-model="consent" class="mt-0.5 size-4 rounded border-zinc-300 dark:border-white/20">
                    <span>
                        <span class="font-medium">Everyone in this conversation agreed to be recorded.</span>
                        <span class="block text-zinc-500 dark:text-zinc-400">Illinois law requires everyone's consent. Ask at the start ("Mind if I record so I don't miss anything?") and tell anyone who joins later.</span>
                    </span>
                </label>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:button size="sm" variant="primary" icon="microphone" x-on:click="start()" x-bind:disabled="! consent || phase === 'starting'">
                        <span x-show="phase !== 'starting'">Start recording</span>
                        <span x-show="phase === 'starting'" x-cloak>Starting…</span>
                    </flux:button>
                    <flux:button size="sm" variant="ghost" x-on:click="cancelConsent()">Cancel</flux:button>
                </div>
            </div>

            {{-- A recording made elsewhere (Voice Memos on the phone, a handheld
                 recorder): posted in pieces and transcribed like a live one. --}}
            <div x-show="phase === 'upload' || phase === 'uploading'" x-cloak class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-white/10" data-meeting-upload>
                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-800 dark:text-white">Recording file</label>
                    <input
                        type="file"
                        x-ref="uploadFile"
                        accept="audio/*,video/mp4,video/quicktime,.m4a,.mp3,.wav,.aac,.ogg,.webm,.mp4,.mov"
                        x-on:change="pickFile($event)"
                        x-bind:disabled="phase === 'uploading'"
                        class="block w-full text-sm text-zinc-800 file:mr-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-800 dark:text-zinc-200 dark:file:bg-white/10 dark:file:text-zinc-200"
                    >
                    <flux:text size="sm" class="mt-1">On the iPhone: record in Voice Memos (it keeps recording with the screen locked), then ⋯ → Share → Save to Files, and pick that file here. Up to {{ (int) config('meeting_recording.max_upload_mb', 1024) }} MB.</flux:text>
                </div>

                <label class="flex items-start gap-2 text-sm text-zinc-800 dark:text-zinc-200">
                    <input type="checkbox" x-model="consent" class="mt-0.5 size-4 rounded border-zinc-300 dark:border-white/20" x-bind:disabled="phase === 'uploading'">
                    <span>
                        <span class="font-medium">Everyone in this conversation agreed to be recorded.</span>
                        <span class="block text-zinc-500 dark:text-zinc-400">Illinois law requires everyone's consent, however the meeting was recorded.</span>
                    </span>
                </label>

                <div x-show="phase === 'uploading'" x-cloak>
                    <div class="h-2 w-full overflow-hidden rounded bg-zinc-100 dark:bg-white/10">
                        <div class="h-full bg-zinc-800 transition-[width] dark:bg-zinc-200" x-bind:style="'width:' + progress + '%'"></div>
                    </div>
                    <flux:text size="sm" class="mt-1">Uploading… <span x-text="progress + '%'"></span> — keep this page open.</flux:text>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <flux:button size="sm" variant="primary" icon="arrow-up-tray" x-on:click="uploadFile()" x-bind:disabled="! consent || ! file || phase === 'uploading'">
                        <span x-show="phase !== 'uploading'">Upload and transcribe</span>
                        <span x-show="phase === 'uploading'" x-cloak>Uploading…</span>
                    </flux:button>
                    <flux:button size="sm" variant="ghost" x-on:click="cancelUpload()" x-bind:disabled="phase === 'uploading'">Cancel</flux:button>
                </div>
            </div>

            {{-- While recording. --}}
            <div x-show="phase === 'recording' || phase === 'stopping'" x-cloak class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-500/30 dark:bg-red-500/10">
                <div class="flex items-center gap-3">
                    <span class="relative flex size-3">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-red-400 opacity-75" x-show="phase === 'recording'"></span>
                        <span class="relative inline-flex size-3 rounded-full bg-red-500"></span>
                    </span>
                    <div>
                        <div class="font-semibold tabular-nums text-red-700 dark:text-red-300" x-text="clock" data-meeting-clock></div>
                        <div class="text-xs text-red-700/80 dark:text-red-300/80">
                            <span x-show="phase === 'recording'">Recording — keep Hive open; the screen stays on.</span>
                            <span x-show="phase === 'stopping'" x-cloak>Saving the last seconds…</span>
                            <span x-show="pending > 1" x-cloak> · saving audio…</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2" x-show="phase === 'recording'">
                    <flux:button size="sm" variant="danger" icon="stop" x-on:click="stop()">Stop</flux:button>
                    <flux:button size="sm" variant="ghost" x-on:click="discard()">Discard</flux:button>
                </div>
                <flux:text size="sm" class="w-full text-amber-700 dark:text-amber-400" x-show="hiddenWhileRecording" x-cloak>
                    Hive left the screen during the recording — on an iPhone, recording pauses while Hive isn't open.
                </flux:text>
            </div>

            <flux:text size="sm" class="text-red-600 dark:text-red-400" x-show="error" x-text="error" x-cloak></flux:text>

            {{-- Recent recordings: transcribing, or their summary and action items. --}}
            @if ($this->processing)
                <div wire:poll.10s></div>
            @endif

            @forelse ($this->recordings as $recording)
                <div wire:key="meeting-recording-{{ $recording->id }}" class="rounded-lg border border-zinc-100 p-3 dark:border-white/5" data-meeting-recording="{{ $recording->status }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:heading size="sm">
                            {{ ($recording->started_at ?? $recording->created_at)->timezone('America/Chicago')->format('D M j, g:i A') }}
                            @if ($recording->duration_seconds)
                                <span class="font-normal text-zinc-500"> · {{ gmdate($recording->duration_seconds >= 3600 ? 'G:i:s' : 'i:s', $recording->duration_seconds) }}</span>
                            @endif
                            @if ($recording->recordedBy)
                                <span class="font-normal text-zinc-500"> · {{ \App\Support\Sms\ConversationPresenter::preferredUserDisplayName($recording->recordedBy, false) }}</span>
                            @endif
                            @if ($recording->source === \App\Models\MeetingRecording::SOURCE_UPLOAD)
                                <span class="font-normal text-zinc-500"> · uploaded file</span>
                            @endif
                        </flux:heading>
                        @switch ($recording->status)
                            @case (\App\Models\MeetingRecording::STATUS_PROCESSING)
                                <flux:badge size="sm" color="amber" icon="arrow-path">Transcribing…</flux:badge>
                                @break
                            @case (\App\Models\MeetingRecording::STATUS_READY)
                                <flux:badge size="sm" color="green" icon="check">Ready</flux:badge>
                                @break
                            @case (\App\Models\MeetingRecording::STATUS_EMPTY)
                                <flux:badge size="sm" color="zinc">Nothing to transcribe</flux:badge>
                                @break
                            @default
                                <flux:badge size="sm" color="red" :title="$recording->failure_reason">Couldn't transcribe</flux:badge>
                        @endswitch
                    </div>

                    @if ($recording->status === \App\Models\MeetingRecording::STATUS_READY)
                        @if ($recording->summary)
                            <flux:text class="mt-2">{{ $recording->summary }}</flux:text>
                        @endif
                        @if ($recording->action_items)
                            <div class="mt-2">
                                <flux:text size="sm" class="font-medium">Action items</flux:text>
                                <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-zinc-700 dark:text-zinc-300">
                                    @foreach ($recording->action_items as $item)
                                        <li>{{ $item }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @if ($recording->transcript)
                            <details class="mt-2">
                                <summary class="cursor-pointer text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300">Transcript</summary>
                                <div class="mt-1 max-h-72 overflow-y-auto whitespace-pre-line rounded bg-zinc-50 p-2 text-xs text-zinc-700 dark:bg-white/5 dark:text-zinc-300">{{ $recording->transcript }}</div>
                            </details>
                        @endif
                    @endif
                </div>
            @empty
                <flux:text size="sm" x-show="phase === 'idle'">No meetings recorded yet.</flux:text>
            @endforelse
        </div>
    </x-island-card>
</div>
