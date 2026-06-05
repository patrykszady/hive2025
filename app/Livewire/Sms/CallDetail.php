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

        return CallLog::with('transcript')->find($this->callId);
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
        $segments = $call->transcript?->segments;
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

        // First pass: identify which raw speaker label belongs to the agent
        // (the one who said any of the welcome/disclosure markers).
        $agentSpeaker = null;
        foreach ($segments as $seg) {
            $text = strtolower(trim((string) ($seg['text'] ?? '')));
            $speaker = $seg['speaker'] ?? null;
            if ($speaker === null || $text === '') {
                continue;
            }
            foreach ($markers as $m) {
                if ($m !== '' && str_contains($text, $m)) {
                    $agentSpeaker = $speaker;
                    break 2;
                }
            }
        }

        // Fallback: if marker detection failed, assume the second distinct
        // speaker is the Hive agent. On both inbound (caller speaks first
        // after disclosure) and outbound (recipient answers "Hello" first)
        // calls, the agent is consistently the second voice we hear.
        if ($agentSpeaker === null) {
            $order = [];
            foreach ($segments as $seg) {
                $sp = $seg['speaker'] ?? null;
                if ($sp !== null && ! in_array($sp, $order, true)) {
                    $order[] = $sp;
                }
            }
            if (isset($order[1])) {
                $agentSpeaker = $order[1];
            }
        }

        $callerName = $this->callerDisplayName($call);
        $agentName = $this->agentDisplayName($call);

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

            if ($skippingPreamble) {
                if ($matchesMarker) {
                    continue;
                }
                $skippingPreamble = false;
            }

            // Map raw "Speaker A/B/C" to friendly labels.
            $friendly = $rawSpeaker;
            if ($rawSpeaker !== null) {
                if ($agentSpeaker !== null && $rawSpeaker === $agentSpeaker) {
                    $friendly = $agentName ?? 'Agent';
                } elseif ($callerName !== null) {
                    $friendly = $callerName;
                } else {
                    $friendly = 'Caller';
                }
            }

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
