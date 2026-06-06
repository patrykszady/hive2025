<?php

namespace App\Livewire\Sms;

use App\Livewire\Sms\Concerns\HasCallActions;
use App\Models\BlockedCaller;
use App\Models\CallLog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\On;
use Livewire\Component;

#[Isolate]
class CallDetail extends Component
{
    use HasCallActions;

    public ?int $callId = null;

    #[On('call-selected')]
    public function selectCall(int $callId): void
    {
        $this->callId = $callId;
    }

    #[On('call-deselected')]
    public function clear(): void
    {
        $this->callId = null;
    }

    #[Computed]
    public function call(): ?CallLog
    {
        if (! $this->callId) {
            return null;
        }

        $user = auth()->user();

        return CallLog::query()
            ->with('transcript')
            ->visibleToMessagesUser($user)
            ->find($this->callId);
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function blockedNumbers(): array
    {
        return BlockedCaller::pluck('phone_number')->all();
    }

    public function effectiveStatus(CallLog $call): string
    {
        if ($call->status === CallLog::STATUS_BLOCKED) {
            return 'blocked';
        }

        $metadata = is_array($call->metadata) ? $call->metadata : (is_string($call->metadata) ? json_decode($call->metadata, true) : []);
        if (! empty($metadata['blocked_reason'])) {
            return 'blocked';
        }

        return $call->status;
    }

    /**
     * Return transcript segments with our system TTS (welcome + recording disclosure)
     * stripped from the start. Each segment carries a `speaker` label
     * (e.g. "Speaker A") when produced by a diarization-capable engine.
     *
     * Speaker labels are remapped to friendly names: the speaker who utters
     * our welcome/disclosure markers becomes "Agent", and the remaining
     * speakers are mapped to the caller's name (for inbound calls) or "Caller".
     *
     * @return array<int, array{text: string, speaker: ?string, start: float|int|null, end: float|int|null}>
     */
    public function cleanedTranscriptSegments(CallLog $call): array
    {
        $transcript = $call->transcript;
        $segments = $transcript?->segments;
        if (! is_array($segments) || empty($segments)) {
            return [];
        }

        $disclosure = strtolower(trim((string) config('call_recording.disclosure.phrase', 'This call is recorded.')));
        $markers = [
            $disclosure,
            'one moment while we connect you',
            'thanks for calling',
            'good morning', 'good afternoon', 'good evening',
        ];
        $voicemailMarkers = [
            'leave a voicemail',
            'leave gs crew a message',
            'press 1 to redial',
            'press 2 to send a text',
            'is not available right now',
        ];

        $labelMap = $transcript->speakerLabelMap($call);

        // In a voicemail there is only ever one human — the caller. The IVR
        // greeting is the "Voicemail" system. AssemblyAI's diarization
        // sometimes lumps a short caller backchannel ("okay, thank you") into
        // the IVR's speaker cluster, which the per-label map would then
        // mislabel as "Voicemail". For voicemails we therefore resolve each
        // segment individually: anything matching an IVR marker is the
        // Voicemail system, everything else is the caller.
        $isVoicemail = (bool) ($call->has_voicemail ?? false);
        $callerName = null;
        if ($isVoicemail) {
            foreach ($labelMap as $name) {
                if ($name !== 'Voicemail') {
                    $callerName = $name;
                    break;
                }
            }
            if (! $callerName && $call->caller_name) {
                $callerName = ucfirst(strtolower(explode(' ', trim((string) $call->caller_name))[0]));
            }
            $callerName ??= 'Caller';
        }

        $cleaned = [];
        $skippingPreamble = true;

        foreach ($segments as $seg) {
            $text = trim((string) ($seg['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $rawSpeaker = $seg['speaker'] ?? null;

            $lower = strtolower($text);
            $matchesMarker = false;
            foreach ($markers as $m) {
                if ($m !== '' && str_contains($lower, $m)) {
                    $matchesMarker = true;
                    break;
                }
            }
            $matchesVoicemail = false;
            foreach ($voicemailMarkers as $m) {
                if ($m !== '' && str_contains($lower, $m)) {
                    $matchesVoicemail = true;
                    break;
                }
            }

            // Voicemail calls: drop the IVR greeting entirely and attribute
            // every remaining segment to the single caller, regardless of the
            // diarization label.
            if ($isVoicemail) {
                if ($matchesVoicemail) {
                    continue;
                }
                $cleaned[] = [
                    'text' => $text,
                    'speaker' => $callerName,
                    'start' => $seg['start'] ?? null,
                    'end' => $seg['end'] ?? null,
                ];
                continue;
            }

            if ($skippingPreamble) {
                if ($matchesMarker || $matchesVoicemail) {
                    continue;
                }
                $skippingPreamble = false;
            }

            // Drop any later voicemail-IVR segments too — they're noise.
            if ($matchesVoicemail) {
                continue;
            }

            $friendly = $rawSpeaker !== null ? ($labelMap[$rawSpeaker] ?? $rawSpeaker) : null;

            $cleaned[] = [
                'text' => $text,
                'speaker' => $friendly,
                'start' => $seg['start'] ?? null,
                'end' => $seg['end'] ?? null,
            ];
        }

        return $cleaned;
    }

    /**
     * Distinct speaker names that appear in the cleaned transcript. Used
     * for the participant badges next to the "Full transcript" header.
     *
     * @return array<int, string>
     */
    public function transcriptSpeakers(CallLog $call): array
    {
        $names = [];
        foreach ($this->cleanedTranscriptSegments($call) as $seg) {
            $name = trim((string) ($seg['speaker'] ?? ''));
            if ($name !== '' && $name !== 'Voicemail' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Friendly first name for the Hive agent on the call (the person who
     * answered for inbound, or initiated click-to-call for outbound).
     */
    protected function agentDisplayName(CallLog $call): ?string
    {
        $agent = $call->agentUser();
        if ($agent && trim((string) $agent->first_name) !== '') {
            return (string) $agent->first_name;
        }
        if ($agent && trim((string) $agent->name) !== '') {
            return explode(' ', trim((string) $agent->name))[0];
        }

        return null;
    }

    /**
     * Pick the friendliest available display name for the "other party" on
     * the call — the inbound caller, or the outbound recipient. Falls back
     * through contact_user_id, phone-number lookup, then stored caller_name.
     */
    protected function callerDisplayName(CallLog $call): ?string
    {
        $contact = $call->contact_user_id ? \App\Models\User::find($call->contact_user_id) : null;
        if (! $contact) {
            $contact = $call->otherPartyUser();
        }

        if ($contact && trim((string) $contact->first_name) !== '') {
            return (string) $contact->first_name;
        }
        if ($contact && trim((string) $contact->name) !== '') {
            return explode(' ', trim((string) $contact->name))[0];
        }

        if ($call->caller_name) {
            $first = explode(' ', trim((string) $call->caller_name))[0] ?? null;
            if ($first) {
                return ucfirst(strtolower($first));
            }
        }

        return null;
    }

    public function render()
    {
        return view('livewire.sms.call-detail');
    }
}
