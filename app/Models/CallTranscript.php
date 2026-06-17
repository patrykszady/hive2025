<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallTranscript extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_TRANSCRIBING = 'transcribing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    /**
     * Terminal status for recordings that transcribed successfully but
     * contained no speech (silent/very short calls). Unlike STATUS_FAILED,
     * these are NOT retried by `calls:process-recordings --retry-failed`,
     * because re-running AssemblyAI on the same silent audio will always
     * return empty — previously this caused an endless re-transcription
     * loop every 5 minutes that burned API spend and flooded the logs.
     */
    public const STATUS_EMPTY = 'empty';

    protected $fillable = [
        'call_log_id',
        'telnyx_recording_id',
        'telnyx_transcription_id',
        'engine',
        'language',
        'text',
        'segments',
        'speaker_map',
        'status',
        'failure_reason',
        'summary_model',
        'summary',
        'action_items',
        'topics',
        'next_steps',
        'sentiment',
        'caller_intent',
        'intelligence',
        'summarized_at',
    ];

    protected function casts(): array
    {
        return [
            'segments' => 'array',
            'speaker_map' => 'array',
            'action_items' => 'array',
            'topics' => 'array',
            'next_steps' => 'array',
            'intelligence' => 'array',
            'summarized_at' => 'datetime',
        ];
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }

    /**
     * Map raw transcript speaker labels ("Speaker A", "Speaker B", ...) to
     * the friendliest available real-world name. Uses the same heuristic as
     * the UI: the speaker who utters our welcome / disclosure phrases is the
     * Hive agent; if no marker is found, the second distinct speaker is the
     * agent. The remaining speaker(s) are mapped to the other-party first
     * name when known. The IVR / voicemail prompt is labeled as "Voicemail".
     *
     * @return array<string, string>  e.g. ["Speaker A" => "Mark", "Speaker B" => "Patryk"]
     */
    public function speakerLabelMap(?CallLog $call = null): array
    {
        $call ??= $this->callLog;
        if (! $call) {
            return [];
        }

        $segments = is_array($this->segments) ? $this->segments : [];
        if ($segments === []) {
            return [];
        }

        // speaker_map is a content-based role map produced by
        // identifySpeakersWithOpenAI: raw diarization label ("A", "B") => the
        // real name of whichever participant (Hive agent / external party) that
        // speaker turned out to be. It is the most reliable signal we have for
        // who is who, and is used below to pin the agent speaker.
        $aaiMap = is_array($this->speaker_map) ? $this->speaker_map : [];
        $aaiNameFor = function (string $speakerLabel) use ($segments, $aaiMap): ?string {
            if ($aaiMap === []) {
                return null;
            }
            foreach ($segments as $seg) {
                if (($seg['speaker'] ?? null) !== $speakerLabel) {
                    continue;
                }
                $rawId = (string) ($seg['speaker_id'] ?? '');
                if ($rawId !== '' && isset($aaiMap[$rawId]) && $aaiMap[$rawId] !== '') {
                    return (string) $aaiMap[$rawId];
                }
            }
            return null;
        };

        $isOutbound = $call->direction !== 'incoming';

        // The recorded-call disclosure phrase is the only reliable agent
        // marker on inbound calls. Greetings ("good morning") are too
        // ambiguous — recipients say them too.
        $agentMarkers = [
            strtolower(trim((string) config('call_recording.disclosure.phrase', 'This call is recorded.'))),
            'one moment while we connect you',
        ];
        $voicemailMarkers = [
            'leave a voicemail',
            'leave gs crew a message',
            'press 1 to redial',
            'press 2 to send a text',
            'is not available right now',
        ];

        $agentSpeaker = null;
        $voicemailSpeaker = null;
        $order = [];

        foreach ($segments as $seg) {
            $sp = $seg['speaker'] ?? null;
            $text = strtolower(trim((string) ($seg['text'] ?? '')));
            if ($sp === null || $text === '') {
                continue;
            }
            if (! in_array($sp, $order, true)) {
                $order[] = $sp;
            }
            if ($voicemailSpeaker === null) {
                foreach ($voicemailMarkers as $m) {
                    if ($m !== '' && str_contains($text, $m)) {
                        $voicemailSpeaker = $sp;
                        break;
                    }
                }
            }
            // Only inbound calls have a reliable agent disclosure phrase.
            if (! $isOutbound && $agentSpeaker === null && $voicemailSpeaker !== $sp) {
                foreach ($agentMarkers as $m) {
                    if ($m !== '' && str_contains($text, $m)) {
                        $agentSpeaker = $sp;
                        break;
                    }
                }
            }
        }

        $agent = $call->agentUser();
        $other = $call->otherPartyUser();

        $agentName = $agent
            ? trim((string) ($agent->first_name ?? '')) ?: explode(' ', trim((string) $agent->name))[0]
            : null;
        $otherName = $other
            ? trim((string) ($other->first_name ?? '')) ?: explode(' ', trim((string) $other->name))[0]
            : null;
        if (! $otherName && $call->caller_name) {
            $otherName = ucfirst(strtolower(explode(' ', trim((string) $call->caller_name))[0]));
        }

        // Most reliable signal: the content-based role map produced by
        // identifySpeakersWithOpenAI (stored in speaker_map) tells us which
        // diarized speaker is the Hive agent. Pin the agent speaker from it,
        // overriding the weaker ordering/disclosure heuristics.
        if ($aaiMap !== [] && $agent) {
            $agentFull = trim((string) $agent->name) !== ''
                ? trim((string) $agent->name)
                : trim(($agent->first_name ?? '') . ' ' . ($agent->last_name ?? ''));
            $agentFull = strtolower(trim($agentFull));
            foreach ($order as $sp) {
                if ($sp === $voicemailSpeaker) {
                    continue;
                }
                $mapped = strtolower(trim((string) $aaiNameFor($sp)));
                if ($mapped !== '' && $mapped === $agentFull) {
                    $agentSpeaker = $sp;
                    break;
                }
            }
        }

        // Fallback when no disclosure phrase or role map was available: infer
        // the Hive agent from who picks up the call, which is determined by
        // the call direction.
        //   • Outbound  — Hive dials out, the recipient (other party) answers
        //                 and speaks first ("Hello?"); the agent speaks second.
        //   • Inbound   — someone calls Hive, the agent picks up and speaks
        //                 first (greeting / disclosure); the caller is second.
        // Voicemails have no live agent at all — the only human is the caller —
        // so we never assign an agent speaker for them.
        if ($agentSpeaker === null && ! $call->has_voicemail) {
            $candidates = array_values(array_filter($order, fn ($s) => $s !== $voicemailSpeaker));
            $agentSpeaker = $isOutbound
                ? ($candidates[1] ?? null)
                : ($candidates[0] ?? null);
        }

        $map = [];
        foreach ($order as $sp) {
            if ($sp === $voicemailSpeaker) {
                $map[$sp] = 'Voicemail';
            } elseif ($sp === $agentSpeaker) {
                $map[$sp] = $agentName ?: $aaiNameFor($sp) ?: 'Agent';
            } else {
                $map[$sp] = $otherName ?: $aaiNameFor($sp) ?: 'Caller';
            }
        }

        return $map;
    }

    /**
     * Render the transcript text with raw speaker labels replaced by the
     * resolved real-world names (or "Voicemail" / "Agent" / "Caller"
     * fallbacks). Used by the summarization prompt so the LLM can reference
     * people by name instead of "Speaker A".
     */
    public function labeledText(?CallLog $call = null): string
    {
        $segments = is_array($this->segments) ? $this->segments : [];
        if ($segments === []) {
            return (string) ($this->text ?? '');
        }

        $map = $this->speakerLabelMap($call);
        $lines = [];
        foreach ($segments as $seg) {
            $sp = $seg['speaker'] ?? null;
            $text = trim((string) ($seg['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $label = $sp !== null ? ($map[$sp] ?? $sp) : 'Speaker';
            $lines[] = $label . ': ' . $text;
        }

        return implode("\n", $lines);
    }
}
