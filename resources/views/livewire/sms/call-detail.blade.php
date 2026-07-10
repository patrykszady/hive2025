<div class="h-full flex flex-col min-h-0">
    @php
        $call = $this->call;
    @endphp

    @if (! $call)
        <x-island-card class="flex-1 flex items-center justify-center text-center">
            <div class="space-y-2 text-zinc-400">
                <flux:icon name="phone" class="size-10 mx-auto opacity-50" />
                <div class="text-sm">Select a call to see details, recording, and AI summary.</div>
            </div>
        </x-island-card>
    @endif
    @if ($call)
        @php
            $isOutgoing = $call->direction === 'outgoing';
            $otherNumber = $isOutgoing ? $call->to_number : $call->from_number;

            if ($otherNumber && \App\Services\GroupSmsService::isOurNumber($otherNumber) && $call->call_session_id) {
                $originalLeg = \App\Models\CallLog::where('call_session_id', $call->call_session_id)
                    ->where('direction', 'incoming')
                    ->where(fn ($q) => $q->whereNotIn('from_number', config('services.telnyx.numbers', [])))
                    ->first();
                if ($originalLeg) {
                    $otherNumber = $originalLeg->from_number;
                }
            }

            $resolvedName = $otherNumber ? $this->resolvePhoneDisplay($otherNumber) : null;
            $formattedOther = $otherNumber ? $this->formatPhone($otherNumber) : null;
            $isKnownContact = $otherNumber ? $this->isKnownContact($otherNumber) : false;
            $displayName = $isKnownContact
                ? ($resolvedName ?? 'Unknown')
                : (($call->caller_name && ! in_array($call->caller_name, ['Incoming Call', 'Outgoing Call'], true))
                    ? $call->display_caller_name
                    : ($resolvedName ?? 'Unknown'));
            $effectiveStatus = $this->effectiveStatus($call);
            $dur = $call->duration_seconds ? abs($call->duration_seconds) : 0;
        @endphp

        <x-island-card class="flex-1 flex flex-col min-h-0 overflow-hidden">
            {{-- Header --}}
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 pb-3 border-b border-zinc-200 dark:border-zinc-700">
                <div class="flex items-center gap-3 min-w-0">
                    <button
                        type="button"
                        x-on:click="$store.sms.callId = null; $wire.clear(); Livewire.dispatch('call-deselected')"
                        class="lg:hidden p-1 -ml-1 rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 shrink-0"
                        aria-label="Back"
                    >
                        <flux:icon name="chevron-left" class="size-5 text-zinc-500" />
                    </button>
                    <div class="shrink-0">
                        @if ($effectiveStatus === 'blocked')
                            <flux:icon icon="shield-exclamation" class="size-6 text-amber-500" />
                        @elseif ($effectiveStatus === 'missed' && $call->has_voicemail)
                            <flux:icon icon="microphone" class="size-6 text-indigo-500" />
                        @elseif ($effectiveStatus === 'missed' && $isOutgoing)
                            <flux:icon icon="phone-arrow-up-right" class="size-6 text-orange-500" />
                        @elseif ($effectiveStatus === 'missed')
                            <flux:icon icon="phone-x-mark" class="size-6 text-red-500" />
                        @elseif ($effectiveStatus === 'failed')
                            <flux:icon icon="x-circle" class="size-6 text-rose-500" />
                        @elseif ($isOutgoing)
                            <flux:icon icon="phone-arrow-up-right" class="size-6 text-indigo-500" />
                        @else
                            <flux:icon icon="phone-arrow-down-left" class="size-6 text-green-500" />
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-base font-semibold text-zinc-900 dark:text-zinc-100 truncate">{{ $displayName }}</div>
                        @if ($formattedOther && $formattedOther !== $displayName)
                            <div class="text-sm text-zinc-500 truncate">{{ $formattedOther }}</div>
                        @endif
                        <div class="text-xs text-zinc-400 mt-0.5 truncate">
                            {{ $call->created_at->copy()->setTimezone(browser_timezone())->format('M j, Y g:i A') }}
                            @if ($dur > 0)
                                · {{ $dur < 60 ? $dur.'s' : floor($dur/60).'m '.($dur%60).'s' }}
                            @endif
                            @if ($effectiveStatus === 'missed' && $call->has_voicemail)
                                · <span class="text-indigo-500 font-medium">Voicemail</span>
                            @elseif ($effectiveStatus === 'missed')
                                · <span class="text-red-500 font-medium">Missed</span>
                            @elseif ($effectiveStatus === 'failed')
                                · <span class="text-rose-500 font-medium">Failed</span>
                            @elseif ($effectiveStatus === 'blocked')
                                · <span class="text-amber-500 font-medium">Blocked</span>
                            @endif
                        </div>
                    </div>
                </div>

                @if ($otherNumber)
                    <div class="flex items-center gap-2 sm:shrink-0 flex-wrap">
                        <flux:button size="sm" variant="primary" icon="phone" wire:click="callBack('{{ $otherNumber }}')">Call Back</flux:button>
                        <flux:button size="sm" variant="ghost" icon="chat-bubble-left" wire:click="textBack('{{ $otherNumber }}')">Text</flux:button>
                        @if ($effectiveStatus === 'blocked' && in_array($otherNumber, $this->blockedNumbers))
                            <flux:button size="sm" variant="ghost" icon="shield-check" wire:click="unblockNumber('{{ $otherNumber }}')">Unblock</flux:button>
                        @elseif (! $this->isKnownContact($otherNumber) && $effectiveStatus !== 'blocked' && ! in_array($otherNumber, $this->blockedNumbers))
                            <flux:button size="sm" variant="ghost" icon="shield-exclamation" wire:click="markAsSpam({{ $call->id }})">Spam</flux:button>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Scrollable body --}}
            <div class="flex-1 min-h-0 overflow-y-auto py-4 space-y-4">
                @if ($call->recording_url && $dur > 0)
                    <div wire:key="recording-{{ $call->id }}">
                        <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-1.5">
                            {{ $call->has_voicemail ? 'Voicemail' : 'Recording' }}
                        </div>
                        <audio controls preload="metadata" class="w-full h-10" src="{{ $call->recording_path && $call->recording_disk ? route('calls.recording', $call) : $call->recording_url }}"></audio>
                    </div>
                @endif

                @php
                    $hasTranscript = $call->transcript && ($call->transcript->summary || $call->transcript->text);
                @endphp
                @if ($hasTranscript)
                    @if ($call->transcript->summary)
                        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/40 p-3 space-y-3">
                            <div class="flex items-center gap-2">
                                <flux:icon name="sparkles" class="size-4 text-amber-500" />
                                <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">AI Summary</span>
                                @if ($call->transcript->language)
                                    <flux:badge size="xs" color="zinc">{{ strtolower($call->transcript->language) }}</flux:badge>
                                @endif
                                @if ($call->transcript->sentiment && $call->transcript->sentiment !== 'neutral')
                                    <flux:badge size="xs" color="{{ match($call->transcript->sentiment) { 'positive' => 'green', 'negative' => 'red', 'mixed' => 'amber', default => 'zinc' } }}">
                                        {{ ucfirst($call->transcript->sentiment) }}
                                    </flux:badge>
                                @endif
                            </div>

                            <div class="text-sm text-zinc-700 dark:text-zinc-200 leading-relaxed">
                                {{ $call->transcript->summary }}
                            </div>

                            @if ($call->transcript->caller_intent)
                                <div>
                                    <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-1">Intent</div>
                                    <div class="text-sm text-zinc-700 dark:text-zinc-200">{{ $call->transcript->caller_intent }}</div>
                                </div>
                            @endif

                            @if (! empty($call->transcript->action_items))
                                <div>
                                    <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-1">Action Items</div>
                                    <ul class="text-sm text-zinc-700 dark:text-zinc-200 list-disc list-inside space-y-0.5">
                                        @foreach ($call->transcript->action_items as $item)
                                            <li>{{ $item }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if (! empty($call->transcript->topics))
                                <div>
                                    <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-1">Topics</div>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($call->transcript->topics as $topic)
                                            <flux:badge size="xs" color="zinc">{{ $topic }}</flux:badge>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if (! empty($call->transcript->next_steps))
                                <div>
                                    <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide mb-1">Next Steps</div>
                                    <ul class="text-sm text-zinc-700 dark:text-zinc-200 list-disc list-inside space-y-0.5">
                                        @foreach ($call->transcript->next_steps as $item)
                                            <li>{{ $item }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($call->transcript->text)
                        @php
                            $cleanSegments = $this->cleanedTranscriptSegments($call);
                            $speakers = $this->transcriptSpeakers($call);
                        @endphp
                        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3">
                            <details>
                                <summary class="cursor-pointer text-sm font-medium text-zinc-700 dark:text-zinc-200 hover:text-zinc-900 dark:hover:text-zinc-100">
                                    <span class="inline-flex items-center gap-2 flex-wrap">
                                        <span>Full transcript</span>
                                        @foreach ($speakers as $i => $sp)
                                            @php
                                                $badgePalette = [
                                                    'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
                                                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
                                                    'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
                                                    'bg-fuchsia-100 text-fuchsia-800 dark:bg-fuchsia-900/40 dark:text-fuchsia-200',
                                                    'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
                                                    'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/40 dark:text-cyan-200',
                                                ];
                                                $cls = $badgePalette[$i % count($badgePalette)];
                                            @endphp
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium {{ $cls }}">{{ $sp }}</span>
                                        @endforeach
                                    </span>
                                </summary>
                                <div class="mt-3 space-y-2 text-sm text-zinc-700 dark:text-zinc-200 leading-relaxed">
                                    @php
                                        $palette = [
                                            ['bubble' => 'bg-blue-50 dark:bg-blue-900/30', 'label' => 'text-blue-700 dark:text-blue-300'],
                                            ['bubble' => 'bg-emerald-50 dark:bg-emerald-900/30', 'label' => 'text-emerald-700 dark:text-emerald-300'],
                                            ['bubble' => 'bg-amber-50 dark:bg-amber-900/30', 'label' => 'text-amber-700 dark:text-amber-300'],
                                            ['bubble' => 'bg-fuchsia-50 dark:bg-fuchsia-900/30', 'label' => 'text-fuchsia-700 dark:text-fuchsia-300'],
                                            ['bubble' => 'bg-rose-50 dark:bg-rose-900/30', 'label' => 'text-rose-700 dark:text-rose-300'],
                                            ['bubble' => 'bg-cyan-50 dark:bg-cyan-900/30', 'label' => 'text-cyan-700 dark:text-cyan-300'],
                                        ];
                                        $speakerStyles = [];
                                        $speakerOrder = [];
                                        foreach ($cleanSegments as $s) {
                                            $sp = $s['speaker'] ?? null;
                                            if ($sp && ! isset($speakerStyles[$sp])) {
                                                $speakerStyles[$sp] = $palette[count($speakerOrder) % count($palette)];
                                                $speakerOrder[] = $sp;
                                            }
                                        }
                                    @endphp
                                    @forelse ($cleanSegments as $seg)
                                        @if (! empty($seg['speaker']))
                                            @php
                                                $style = $speakerStyles[$seg['speaker']] ?? $palette[0];
                                                $alignRight = ($speakerOrder[0] ?? null) !== $seg['speaker'];
                                            @endphp
                                            <div class="flex {{ $alignRight ? 'justify-end' : 'justify-start' }}">
                                                <div class="max-w-[85%] rounded-2xl {{ $alignRight ? 'rounded-br-sm' : 'rounded-bl-sm' }} {{ $style['bubble'] }} px-3 py-2">
                                                    <div class="text-[10px] font-medium uppercase tracking-wide {{ $style['label'] }} mb-0.5">{{ $seg['speaker'] }}</div>
                                                    <div class="text-zinc-800 dark:text-zinc-100">{{ $seg['text'] }}</div>
                                                </div>
                                            </div>
                                        @else
                                            <p>{{ $seg['text'] }}</p>
                                        @endif
                                    @empty
                                        <p class="whitespace-pre-wrap text-zinc-600 dark:text-zinc-300">{{ $call->transcript->text }}</p>
                                    @endforelse
                                </div>
                            </details>
                        </div>
                    @endif
                @endif
                @if (! $hasTranscript
                    && $call->recording_url
                    && $dur > 0
                    && ! $call->has_voicemail
                    && $call->recording_started_at
                    && (! $call->transcript || in_array($call->transcript->status, [\App\Models\CallTranscript::STATUS_PENDING, \App\Models\CallTranscript::STATUS_TRANSCRIBING], true)))
                    <div class="text-sm text-zinc-500 italic flex items-center gap-2">
                        <flux:icon name="ellipsis-horizontal" class="size-4 animate-pulse" />
                        Transcript and AI summary processing…
                    </div>
                @endif
            </div>
        </x-island-card>
    @endif
</div>
